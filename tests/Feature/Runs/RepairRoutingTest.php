<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Enums\VerificationStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

class RepairRoutingTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected FakeCodingAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'sdk',
            'builder.construction.budgets.repairs' => 5,
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'builder.agents.order' => ['claude'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);

        FeaturePlanner::fake([[
            'summary' => 'Teams get an optional description.',
            'acceptance_criteria' => ['Teams have a description.'],
            'assumptions' => [],
            'tasks' => ['Add a description.'],
            'understood_as' => 'Data change',
            'current_behavior' => 'Teams have only a name.',
            'preserve' => [],
            'steps' => [['key' => 'description', 'kind' => 'data', 'label' => 'Description', 'file' => 'app/Models/Team.php', 'symbol' => 'Team', 'detail' => 'Holds a description.']],
        ]]);
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => []]]);

        $agent = $this->agent = new FakeCodingAgent('anthropic', function (Workspace $workspace, AgentTask $task) {
            File::put(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/app/Team.php", "<?php\n// ".count($this->agent->tasks)."\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.', session: 'session-'.count($this->agent->tasks));
        });
        app(CodingAgentManager::class)->extend('claude', fn () => $agent);
    }

    public function test_one_failing_test_goes_to_the_light_model_and_back_to_the_usual_one_if_that_did_not_pass()
    {
        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->failChecks($run, [$this->tests(['teams have a description' => 'failed', 'teams have a name' => 'passed'])]);
        $this->failChecks($run, [$this->tests(['teams have a description' => 'failed'])]);
        $this->failChecks($run, [$this->tests(['teams have a description' => 'failed'])]);

        $this->assertSame([false, true, false, true], array_map(fn (AgentTask $task) => $task->light, $this->agent->tasks));
        $this->assertSame([false, true, false, true], $run->events()->where('type', 'model_call')->where('data->role', 'coder')->orderBy('sequence')->get()->map(fn (RunEvent $event) => $event->data['light'])->all());
    }

    public function test_only_a_problem_a_check_can_judge_goes_to_the_light_model()
    {
        $run = app(StartRun::class)->handle($this->request())->refresh();

        // Two failing tests.
        $this->failChecks($run, [$this->tests(['teams have a description' => 'failed', 'teams have a name' => 'failed'])]);
        // Two checks at once.
        $this->failChecks($run, [$this->tests(['teams have a description' => 'failed']), $this->check('PHP formatting', 'app/Team.php')]);
        // Static analysis with more than one error.
        $this->failChecks($run, [$this->check('Static analysis', "[ERROR] Found 2 errors\n")]);
        // A check that says nothing about how many problems it found.
        $this->failChecks($run, [$this->check('TypeScript', 'error TS2322')]);
        // A check that ran out of time.
        $this->failChecks($run, [[...$this->check('PHP formatting', ''), 'timed_out' => true]]);

        $this->assertSame([false, false, false, false, false, false], array_map(fn (AgentTask $task) => $task->light, $this->agent->tasks));
    }

    public function test_one_static_analysis_error_or_a_formatting_failure_goes_to_the_light_model()
    {
        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->failChecks($run, [$this->check('Static analysis', "[ERROR] Found 1 error\n")]);
        $this->failChecks($run, [$this->check('Tests', 'Boom', ['outcome' => 'errored'])]);
        $this->failChecks($run, [$this->check('PHP formatting', "  ⨯ app/Team.php\n")]);

        $this->assertSame([false, true, false, true], array_map(fn (AgentTask $task) => $task->light, $this->agent->tasks));
    }

    public function test_after_two_repairs_that_did_not_pass_the_other_agent_starts_fresh()
    {
        config(['builder.agents.order' => ['claude', 'codex'], 'ai.providers.openai.key' => 'openai-test-key']);
        $codex = new FakeCodingAgent('openai', function (Workspace $workspace) use (&$codex) {
            File::put(config('workspaces.drivers.local.root')."/{$workspace->driver_id}/app/Team.php", "<?php\n// codex ".count($codex->tasks)."\n");

            return new AgentOutcome('codex', 'openai', null, AgentOutcomeStatus::Completed, 'Done.', session: 'codex-'.count($codex->tasks));
        });
        app(CodingAgentManager::class)->extend('codex', fn () => $codex);
        $run = app(StartRun::class)->handle($this->request())->refresh();
        $twoTests = [$this->tests(['teams have a description' => 'failed', 'teams have a name' => 'failed'])];

        $this->failChecks($run, $twoTests);
        $this->failChecks($run, $twoTests);
        $this->assertCount(3, $this->agent->tasks);
        $this->assertSame([], $codex->tasks);

        $this->failChecks($run, $twoTests);

        [$handover] = $codex->tasks;
        $this->assertSame('codex', $handover->prefer);
        $this->assertNull($handover->resume, 'It reads the whole brief, not the stalled session.');
        $this->assertStringContainsString("Owner's request", $handover->prompt);
        $this->assertStringContainsString('Tests failed:', $handover->prompt);
        $this->assertSame(['from' => 'claude', 'to' => 'codex'], $run->events()->where('type', 'escalated')->sole()->data);

        // It happens once: the next repair continues the new agent's session.
        $this->failChecks($run, $twoTests);
        $this->assertCount(2, $codex->tasks);
        $this->assertSame('codex-1', $codex->tasks[1]->resume['session'] ?? null);
        $this->assertCount(3, $this->agent->tasks);
    }

    public function test_light_repairs_can_be_turned_off()
    {
        config(['builder.agents.light_repairs' => false]);
        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->failChecks($run, [$this->tests(['teams have a description' => 'failed'])]);

        $this->assertSame([false, false], array_map(fn (AgentTask $task) => $task->light, $this->agent->tasks));
    }

    /**
     * Record a failing verification with the given results and carry it back.
     *
     * @param  list<array<string, mixed>>  $results
     */
    protected function failChecks(Run $run, array $results): void
    {
        $verification = $run->refresh()->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => VerificationStatus::Failed, 'results' => $results, 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }

    /**
     * @param  array<string, string>  $tests
     * @return array<string, mixed>
     */
    protected function tests(array $tests): array
    {
        return [
            ...$this->check('Tests', 'FAILED'),
            'tests' => array_map(fn (string $name, string $outcome) => ['file' => 'tests/Feature/TeamTest.php', 'name' => $name, 'outcome' => $outcome], array_keys($tests), $tests),
        ];
    }

    /**
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    protected function check(string $name, string $output, array $with = []): array
    {
        return ['name' => $name, 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => $output, ...$with];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Give teams a description.']);
    }
}
