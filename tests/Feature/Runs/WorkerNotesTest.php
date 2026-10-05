<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\GrantWorkerAccess;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\NotesKeeper;
use App\Context\ProjectNotes;
use App\Enums\RunStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class WorkerNotesTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    private const TEAMS = "---\ncapability: teams\npaths: [app/Models/Team.php]\n---\n# Teams\n\nA team has a name.\n";

    private const BILLING = "---\ncapability: billing\npaths: [app/Billing/*]\n---\n# Billing\n\nEach team pays monthly.\n";

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        Sleep::fake();
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'worker',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);

        $plan = [
            'summary' => 'Teams get an optional description.',
            'acceptance_criteria' => ['Teams have a nullable description.'],
            'cases' => [['base' => 'A team saved with a description keeps it.', 'alternate' => null, 'no_alternate' => 'A description is only set one way.', 'exception' => null, 'no_exception' => 'Nothing about a description is refused.']],
            'assumptions' => [],
            'tasks' => ['Add a nullable description property.'],
            'capabilities' => [],
            'understood_as' => 'Data change',
            'current_behavior' => 'Teams have only a name.',
            'preserve' => [],
            'steps' => [[
                'key' => 'description-field',
                'kind' => 'data',
                'label' => 'Team description',
                'file' => 'app/Models/Team.php',
                'symbol' => 'Team::$description',
                'detail' => 'Holds an optional description.',
            ]],
        ];
        // One plan for each run a test starts.
        FeaturePlanner::fake([$plan, $plan]);
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => []]]);
    }

    public function test_a_workers_change_updates_the_notes_of_the_areas_it_touched()
    {
        $updated = "---\ncapability: teams\npaths: [app/Models/Team.php]\n---\n# Teams\n\nA team has a name and may have a description.\n";
        NotesKeeper::fake([['files' => [
            ['path' => 'capabilities/teams.md', 'contents' => $updated],
            // Notes it was not shown are never written.
            ['path' => 'capabilities/billing.md', 'contents' => 'Rewritten.'],
            ['path' => '../app/Models/Team.php', 'contents' => 'Rewritten.'],
        ]]]);

        $run = $this->submit($this->startRun());

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['capabilities/teams.md' => ['before' => self::TEAMS, 'after' => $updated]], $run->featureRequest->note_changes);
        $this->assertStringNotContainsString('Rewritten.', (string) $run->featureRequest->patch);
        NotesKeeper::assertPrompted(fn ($prompt) => $prompt->contains('Added a description.')
            && $prompt->contains('+    public ?string $description = null;')
            && $prompt->contains('A team has a name.')
            && ! $prompt->contains('Each team pays monthly.'));
        $this->assertSame(1, $run->events()->where('type', 'model_call')->where('data->role', 'reviewer')->count());
    }

    public function test_a_change_no_described_area_claims_or_a_switched_off_keeper_asks_nothing()
    {
        NotesKeeper::fake([['files' => []]]);

        // Billing has notes, but the change is to teams, which has none.
        $run = $this->submit($this->startRun(['capabilities/billing.md' => self::BILLING]));
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->featureRequest->note_changes);

        config(['builder.agents.workers.keep_notes' => false]);
        $run = $this->submit($this->startRun());
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertNull($run->featureRequest->note_changes);

        NotesKeeper::assertNeverPrompted();
    }

    public function test_a_failed_update_keeps_the_notes_and_the_change_and_says_why()
    {
        NotesKeeper::fake(fn () => throw new RuntimeException('The provider is down.'));

        $run = $this->submit($this->startRun());

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('+    public ?string $description = null;', (string) $run->featureRequest->patch);
        $this->assertNull($run->featureRequest->note_changes);
        $this->assertSame('The provider is down.', $run->events()->where('type', 'notes_not_updated')->sole()->data['reason']);
    }

    /**
     * Start a worker's run on an app whose notes describe teams and billing.
     *
     * @param  array<string, string>|null  $notes
     */
    private function startRun(?array $notes = null): Run
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        app(ProjectNotes::class)->put($project, 'main', ['project.md' => "# Teams\n\nPeople work in teams.\n", ...$notes ?? ['capabilities/teams.md' => self::TEAMS, 'capabilities/billing.md' => self::BILLING]]);
        $featureRequest = FeatureRequest::factory()->for($project)->create(['prompt' => 'Give teams a description.']);

        return app(StartRun::class)->handle($featureRequest)->refresh();
    }

    /**
     * Hand back the worker's change: a description on the team.
     */
    private function submit(Run $run): Run
    {
        $patch = <<<'PATCH'
            diff --git a/app/Models/Team.php b/app/Models/Team.php
            --- a/app/Models/Team.php
            +++ b/app/Models/Team.php
            @@ -3,4 +3,5 @@
             class Team
             {
                 public string $name = 'Team';
            +    public ?string $description = null;
             }

            PATCH;

        $this->assertSame(RunStatus::Implementing, $run->status, (string) $run->error);
        $this->tool('submit_change', app(GrantWorkerAccess::class)->handle($run), ['patch' => $patch, 'summary' => 'Added a description.']);

        return $run->refresh();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function tool(string $tool, string $token, array $arguments): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
            ->assertOk();
    }
}
