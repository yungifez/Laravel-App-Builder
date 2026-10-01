<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\DescribeRunProgress;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Run;
use App\Models\Verification;
use App\Models\Workspace;
use App\Runs\Agents\RunnerAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class RunProgressTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_the_owner_sees_which_part_of_their_app_is_being_changed()
    {
        [$run] = $this->implementingRun();
        $run->update(['context' => ['outline' => [
            ['key' => 'teams', 'name' => 'Teams', 'paths' => ['app/Models/Team.php', 'config/teams.php']],
            ['key' => 'billing', 'name' => 'Billing', 'paths' => ['app/Billing/*']],
        ]]]);

        $this->assertNull($this->progress($run));

        $this->writeProgress($run, ['doing' => 'reading', 'last' => 'config/teams.php', 'read' => ['config/teams.php'], 'changed' => []]);
        $this->assertSame(['text' => 'Reading how Teams works', 'changed' => 0], $this->progress($run));

        $this->writeProgress($run, ['doing' => 'changing', 'last' => 'app/Billing/Plan.php', 'read' => [], 'changed' => ['app/Models/Team.php', 'app/Billing/Plan.php', '.product-notes/project.md']]);
        $this->assertSame(['text' => 'Changing Teams and Billing', 'changed' => 2], $this->progress($run));

        $this->writeProgress($run, ['doing' => 'changing', 'last' => 'tests/Feature/TeamTest.php', 'read' => [], 'changed' => ['tests/Feature/TeamTest.php']]);
        $this->assertSame('Writing a test for it', $this->progress($run)['text']);

        $this->writeProgress($run, ['doing' => 'testing', 'last' => null, 'read' => [], 'changed' => []]);
        $this->assertSame('Trying it out', $this->progress($run)['text']);

        $this->actingAs($run->featureRequest->project->owner)
            ->get(route('projects.show', ['project' => $run->featureRequest->project, 'change' => $run->featureRequest->uuid]))
            ->assertInertia(fn (Assert $page) => $page->where('change.run.progress.text', 'Trying it out'));
    }

    public function test_the_owner_sees_which_check_runs_and_how_far_the_checks_have_come()
    {
        config(['builder.verification.setup' => [['name' => 'Install PHP dependencies']], 'builder.verification.checks' => [
            ['name' => 'Tests'], ['name' => 'Static analysis'], ['name' => 'Their own check'],
        ]]);
        $run = Run::factory()->create(['status' => RunStatus::Verifying]);
        $verification = Verification::factory()->create(['feature_request_id' => $run->feature_request_id, 'run_id' => $run->id, 'status' => VerificationStatus::Running, 'results' => []]);

        $this->assertSame(['text' => 'Getting a fresh copy of your app ready to check', 'changed' => 0], $this->progress($run));

        $verification->update(['results' => [['name' => 'Install PHP dependencies', 'stage' => 'setup', 'outcome' => 'passed']]]);
        $this->assertSame('Running your app\'s tests, check 1 of 3', $this->progress($run)['text']);

        $verification->update(['results' => [...$verification->results, ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed'], ['name' => 'Static analysis', 'stage' => 'checks', 'outcome' => 'failed']]]);
        $this->assertSame('Running “Their own check”, check 3 of 3', $this->progress($run)['text']);

        $verification->update(['results' => [...$verification->results, ['name' => 'Their own check', 'stage' => 'checks', 'outcome' => 'passed']]]);
        $this->assertSame('Trying it the way you asked for it', $this->progress($run)['text']);

        // Between checks, the stage alone is said.
        $verification->update(['status' => VerificationStatus::Passed]);
        $this->assertNull($this->progress($run));
    }

    public function test_a_waiting_change_says_how_many_changes_go_first()
    {
        $making = Run::factory()->create(['status' => RunStatus::Implementing]);
        Run::factory()->create(['status' => RunStatus::Verifying]);
        $waiting = Run::factory()->create(['status' => RunStatus::Queued]);
        $after = Run::factory()->create(['status' => RunStatus::Queued]);

        // A change being checked does not hold up the next one.
        $this->assertSame(['text' => 'Waiting its turn, 1 change ahead', 'changed' => 0], $this->progress($waiting));
        $this->assertSame('Waiting its turn, 2 changes ahead', $this->progress($after)['text']);

        $making->update(['status' => RunStatus::Completed]);
        $this->assertNull($this->progress($waiting));
    }

    public function test_the_owner_sees_what_the_change_is_checked_against_while_it_is_looked_over()
    {
        $run = Run::factory()->create(['status' => RunStatus::Reviewing, 'plan' => ['acceptance_criteria' => ['Owners can invite.', 'Members cannot.'], 'preserve' => [['area' => null, 'statement' => 'Owners can rename a team.']]]]);

        $this->assertSame(['text' => 'Checking it against the 2 things you asked for, and the one rule that must not change', 'changed' => 0], $this->progress($run));

        $run->update(['plan' => ['acceptance_criteria' => ['Owners can invite.']]]);
        $this->assertSame('Checking it against the one thing you asked for', $this->progress($run)['text']);

        $run->update(['plan' => ['acceptance_criteria' => []]]);
        $this->assertNull($this->progress($run));
    }

    public function test_the_owner_sees_which_part_of_planning_runs()
    {
        $run = Run::factory()->create(['status' => RunStatus::Planning]);
        $run->recordEvent('status', ['from' => 'queued', 'to' => 'planning']);

        $this->assertSame(['text' => 'Getting a copy of your app ready', 'changed' => 0], $this->progress($run));

        $run->recordEvent('workspace_ready', ['workspace_id' => 1]);
        $this->assertSame('Reading how your app is put together', $this->progress($run)['text']);

        $run->recordEvent('compatibility', ['keep_old_working' => true, 'chosen_by_owner' => false]);
        $this->assertSame('Deciding what to change, and how to prove it works', $this->progress($run)['text']);

        // Planning again after the owner answers a question reuses the copy.
        $run->update(['workspace_id' => Workspace::factory()->create(['status' => WorkspaceStatus::Ready])->id]);
        $run->recordEvent('status', ['from' => 'needs_user_decision', 'to' => 'planning']);
        $this->assertSame('Reading how your app is put together', $this->progress($run)['text']);
    }

    /**
     * Describe the run's progress, without the short cache.
     *
     * @return array{text: string, changed: int}|null
     */
    protected function progress(Run $run): ?array
    {
        Cache::forget("runs:{$run->id}:progress");

        return app(DescribeRunProgress::class)->handle($run->refresh());
    }

    /**
     * Write the progress file as the coding agent's runner does.
     *
     * @param  array<string, mixed>  $progress
     */
    protected function writeProgress(Run $run, array $progress): void
    {
        $path = $this->workspaceFile($run, RunnerAgent::TASK_DIRECTORY.'/progress.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($progress));
    }
}
