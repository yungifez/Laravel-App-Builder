<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\DescribeRunProgress;
use App\Models\Run;
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
            ->get(route('projects.show', ['project' => $run->featureRequest->project_id, 'change' => $run->featureRequest->id]))
            ->assertInertia(fn (Assert $page) => $page->where('change.run.progress.text', 'Trying it out'));
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
