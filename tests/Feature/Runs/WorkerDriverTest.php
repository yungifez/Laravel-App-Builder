<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\GrantWorkerAccess;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\ExecuteRun;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class WorkerDriverTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'worker',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);

        FeaturePlanner::fake([[
            'summary' => 'Teams get an optional description.',
            'acceptance_criteria' => ['Teams have a nullable description.'],
            'assumptions' => [],
            'tasks' => ['Add a nullable description property.'],
            'capabilities' => [],
            'understood_as' => 'Data change',
            'current_behavior' => 'Teams have only a name.',
            'preserve' => [['area' => null, 'statement' => 'Team names stay required.']],
            'steps' => [[
                'key' => 'description-field',
                'kind' => 'data',
                'label' => 'Team description',
                'file' => 'app/Models/Team.php',
                'symbol' => 'Team::$description',
                'detail' => 'Holds an optional description.',
            ]],
        ]]);
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => []]]);
    }

    public function test_the_run_waits_for_the_worker_and_tells_it_how_to_hand_the_change_back()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->assertSame(RunStatus::Implementing, $run->status, json_encode($run->events()->pluck('data', 'type')));
        $this->assertNotNull($run->workspace_id);
        $this->assertSame(0, $run->events()->where('type', 'model_call')->where('data->role', 'coder')->count());

        $this->tool('get_task', $token)->assertSee('Teams get an optional description.')->assertSee('submit_change');
        $this->tool('check_status', $token)->assertSee('Waiting for your change.');
    }

    public function test_a_handed_back_change_is_applied_and_checked_and_a_repair_is_the_whole_change_again()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Added a description.'])
            ->assertSee('Received.');

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('+    public ?string $description = null;', (string) $run->featureRequest->patch);
        $this->assertSame('Added a description.', $run->events()->where('type', 'build_finished')->sole()->data['account']);
        $this->tool('check_status', $token)->assertSee('being checked');

        // The checks send it back: the worker hears so, and hands back the whole change again.
        $this->verify($run, VerificationStatus::Failed);
        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->tool('check_status', $token)->assertSee('The checks found problems.');
        $this->tool('get_task', $token)->assertSee('Fix these problems');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Fixed it.'])->assertSee('Received.');
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);

        // The review sends it back too, in words the worker can act on.
        $this->verify($run, VerificationStatus::Passed);
        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->tool('get_task', $token)->assertSee('No test in the change checks: Teams have a nullable description.');
    }

    public function test_a_patch_that_does_not_apply_is_refused_with_the_reason_and_can_be_handed_back_again()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => str_replace("'Team'", "'Crew'", $this->workersChange()), 'summary' => 'Wrong base.'])
            ->assertSee('Received.');

        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $this->tool('check_status', $token)
            ->assertSee('Your change did not apply')
            ->assertSee('Team.php: patch does not apply')
            ->assertDontSee('agent-task');

        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Right base.'])->assertSee('Received.');
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
        $this->assertStringNotContainsString('Crew', (string) $run->featureRequest->patch);
    }

    public function test_a_change_is_taken_only_while_the_run_waits_for_one()
    {
        $run = $this->startRun();
        $token = app(GrantWorkerAccess::class)->handle($run);

        $this->tool('submit_change', $token, ['patch' => str_repeat('x', 3 * 1024), 'summary' => 'Too big.'], ['builder.agents.workers.max_patch_kb' => 2])
            ->assertSee('too large');
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Done.']);

        // Once it is being checked there is nothing to hand back.
        $this->tool('submit_change', $token, ['patch' => $this->workersChange(), 'summary' => 'Again.'])->assertSee('not waiting for a patch');
        $this->assertSame(1, $run->events()->where('type', 'worker_submitted')->count());

        // A change our own agent writes takes no patch from outside.
        $ours = Run::factory()->implementing()->create(['driver' => 'sdk', 'plan' => $run->plan]);
        $this->tool('submit_change', app(GrantWorkerAccess::class)->handle($ours), ['patch' => $this->workersChange(), 'summary' => 'Sneaky.'])
            ->assertSee('nothing to hand back');
        $this->assertSame(0, $ours->events()->where('type', 'worker_submitted')->count());
    }

    public function test_the_reconciler_leaves_a_run_that_waits_for_its_worker_alone()
    {
        $run = $this->startRun();
        Queue::fake([ExecuteRun::class]);
        $this->travel((int) config('builder.construction.lease_seconds') + 60)->seconds();

        $this->artisan('runs:reconcile')->assertSuccessful();
        Queue::assertNothingPushed();

        // A change handed back while no one picked it up is resumed.
        $run->recordEvent('worker_submitted', ['patch' => $this->workersChange(), 'summary' => 'Done.']);
        $run->touch();
        $this->travel((int) config('builder.construction.lease_seconds') + 60)->seconds();

        $this->artisan('runs:reconcile')->assertSuccessful();
        Queue::assertPushed(ExecuteRun::class, 1);
    }

    protected function startRun(): Run
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);
        $featureRequest = FeatureRequest::factory()->for($project)->create(['prompt' => 'Give teams a description.']);

        return app(StartRun::class)->handle($featureRequest)->refresh();
    }

    /**
     * The worker's change: a description on the team, against the starting point.
     */
    protected function workersChange(): string
    {
        return <<<'PATCH'
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
    }

    /**
     * Call one of the change's tools as a worker's MCP client would.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $config
     */
    protected function tool(string $tool, string $token, array $arguments = [], array $config = []): TestResponse
    {
        config($config);

        return $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson(route('mcp.task'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])
            ->assertOk();
    }

    protected function verify(Run $run, VerificationStatus $status): void
    {
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => $status, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => $status === VerificationStatus::Passed ? 'passed' : 'failed', 'exit_code' => $status === VerificationStatus::Passed ? 0 : 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'Team has no description.'],
        ], 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }
}
