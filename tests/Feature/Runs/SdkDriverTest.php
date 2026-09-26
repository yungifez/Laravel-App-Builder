<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeCodingAgent;
use Tests\TestCase;

class SdkDriverTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    /** @var array<string, FakeCodingAgent> */
    protected array $agents = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();

        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.openai.key' => 'openai-test-key',
            'ai.providers.anthropic.key' => 'anthropic-test-key',
            'builder.agents.reviewers' => [
                'anthropic' => ['provider' => 'openai', 'model' => 'openai-reviewer'],
                'openai' => ['provider' => 'anthropic', 'model' => 'anthropic-reviewer'],
            ],
        ]);

        FeaturePlanner::fake([$this->plan()]);
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => []]]);
    }

    public function test_the_primary_agent_builds_the_change_and_the_other_provider_reviews_it()
    {
        $this->agent('claude', 'anthropic', $this->writes('claude', 'anthropic', 'app/Models/Team.php', "<?php\n\nclass Team\n{\n    public ?string \$description = null;\n}\n"));
        $this->agent('codex', 'openai', fn () => $this->fail('The failover agent must not run.'));

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('+    public ?string $description = null;', (string) $featureRequest->refresh()->patch);
        $this->assertStringContainsString("## Keep as it is\n\nDo not change these.", $this->agents['claude']->tasks[0]->prompt);
        $this->assertStringContainsString('## How to work', $this->agents['claude']->tasks[0]->prompt);
        $this->assertSame(['claude'], $run->events()->where('type', 'model_call')->where('data->role', 'coder')->get()->pluck('data.adapter')->all());
        $this->assertSame(0, $run->events()->where('type', 'failover')->count());

        $this->passVerification($run);

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() === 'openai' && $prompt->model === 'openai-reviewer');
    }

    public function test_provider_trouble_resets_the_workspace_and_fails_over_to_the_other_agent()
    {
        $this->agent('claude', 'anthropic', function (Workspace $workspace) {
            File::put($this->path($workspace, 'app/Partial.php'), "<?php // half done\n");
            File::put($this->path($workspace, 'config/teams.php'), "<?php // broken\n");

            return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::ProviderUnavailable, errorKind: 'overloaded');
        });
        $this->agent('codex', 'openai', $this->writes('codex', 'openai', 'app/Codex.php', "<?php\n"));

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $patch = (string) $featureRequest->refresh()->patch;
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('app/Codex.php', $patch);
        $this->assertStringNotContainsString('app/Partial.php', $patch);
        $this->assertStringNotContainsString('config/teams.php', $patch);
        $this->assertSame(['claude', 'codex'], $run->events()->where('type', 'model_call')->where('data->role', 'coder')->get()->pluck('data.adapter')->all());
        $this->assertSame(['from' => 'claude', 'to' => 'codex', 'reason' => 'overloaded'], $run->events()->where('type', 'failover')->sole()->data);

        $this->passVerification($run);

        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() === 'anthropic' && $prompt->model === 'anthropic-reviewer');
    }

    public function test_without_credentials_for_the_other_provider_the_default_reviewer_is_used_and_logged()
    {
        config(['ai.providers.openai.key' => null, 'builder.models.reviewer' => ['provider' => 'anthropic', 'model' => 'default-reviewer']]);
        $this->agent('claude', 'anthropic', $this->writes('claude', 'anthropic', 'app/Claude.php', "<?php\n"));

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);

        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() === 'anthropic' && $prompt->model === 'default-reviewer');
        $this->assertSame(['built_by' => 'anthropic', 'wanted' => 'openai', 'reason' => 'no_credentials'], $run->events()->where('type', 'reviewer_not_independent')->sole()->data);
    }

    public function test_a_failed_change_is_not_failed_over()
    {
        $this->agent('claude', 'anthropic', fn () => $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Failed, errorKind: 'error_during_execution', error: 'The tests would not pass.'));
        $this->agent('codex', 'openai', fn () => $this->fail('A failed change must not fail over.'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('The agent could not make the change: The tests would not pass.', $run->error);
        $this->assertSame(0, $run->events()->where('type', 'failover')->count());
    }

    public function test_used_up_turns_stop_the_run_for_the_owner()
    {
        $this->agent('claude', 'anthropic', fn () => $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Failed, errorKind: 'error_max_turns'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('budget_exhausted', $run->events()->where('type', 'status')->get()->last()?->data['reason']);
    }

    public function test_when_no_provider_can_serve_the_task_the_run_stops_for_the_owner_with_the_workspace_untouched()
    {
        $unavailable = fn (string $adapter, string $provider) => function (Workspace $workspace) use ($adapter, $provider) {
            File::put($this->path($workspace, "app/{$adapter}.php"), "<?php\n");

            return $this->outcome($adapter, $provider, AgentOutcomeStatus::ProviderUnavailable, errorKind: 'rate_limit', error: 'Rate limited.');
        };
        $this->agent('claude', 'anthropic', $unavailable('claude', 'anthropic'));
        $this->agent('codex', 'openai', $unavailable('codex', 'openai'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertStringContainsString('No AI provider could take the task right now (Rate limited.)', (string) $run->error);
        $this->assertFileDoesNotExist($this->path($run->workspace, 'app/claude.php'));
        $this->assertFileDoesNotExist($this->path($run->workspace, 'app/codex.php'));
    }

    public function test_an_agent_whose_provider_keeps_failing_is_tried_last()
    {
        Cache::put('builder:agents:claude:provider-failures', 3, now()->addMinutes(10));
        $this->agent('claude', 'anthropic', fn () => $this->fail('The agent with an open circuit must be tried last.'));
        $this->agent('codex', 'openai', $this->writes('codex', 'openai', 'app/Codex.php', "<?php\n"));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(['codex'], $run->events()->where('type', 'model_call')->where('data->role', 'coder')->get()->pluck('data.adapter')->all());
        $this->assertFalse(Cache::has('builder:agents:codex:provider-failures'));
    }

    public function test_changes_to_protected_paths_are_put_back()
    {
        $this->agent('claude', 'anthropic', function (Workspace $workspace) {
            File::put($this->path($workspace, 'tests/Acceptance/Contract.php'), "<?php // weakened\n");
            File::put($this->path($workspace, 'tests/Acceptance/New.php'), "<?php\n");
            File::put($this->path($workspace, 'app/Real.php'), "<?php\n");

            return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Completed, summary: 'Done.');
        });

        app(StartRun::class)->handle($featureRequest = $this->request());

        $patch = (string) $featureRequest->refresh()->patch;
        $this->assertStringContainsString('app/Real.php', $patch);
        $this->assertStringNotContainsString('tests/Acceptance', $patch);
    }

    public function test_the_runner_agent_passes_the_task_and_credentials_and_reads_the_result_line()
    {
        config([
            'ai.providers.anthropic.key' => 'test-anthropic-key',
            'builder.agents.runner.path' => base_path('tests/Fixtures/fake-agent-runner.mjs'),
            'builder.agents.adapters.claude.model' => 'claude-opus-5',
        ]);
        FeaturePlanner::fake([$this->plan()]);

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $call = $run->events()->where('type', 'model_call')->where('data->role', 'coder')->sole()->data;
        $this->assertSame('completed', $call['status']);
        $this->assertSame([1200, 300, 0.42, 3], [$call['input_tokens'], $call['output_tokens'], $call['cost_usd'], $call['turns']]);
        $this->assertSame('model=claude-opus-5 turns='.config('builder.agents.max_turns').' key=present', $run->events()->where('type', 'build_finished')->sole()->data['account']);
        $this->assertStringContainsString('agent-output.txt', (string) $featureRequest->refresh()->patch);
        $this->assertStringNotContainsString('.builder-run', (string) $featureRequest->patch);
        $this->assertFalse(WorkspaceCommand::query()->get()->contains(fn (WorkspaceCommand $command) => str_contains((string) json_encode($command->command), 'test-anthropic-key')));
    }

    /**
     * Register a scripted agent.
     *
     * @param  Closure(Workspace, AgentTask): AgentOutcome  $behaviour
     */
    protected function agent(string $adapter, string $provider, Closure $behaviour): void
    {
        $agent = $this->agents[$adapter] = new FakeCodingAgent($provider, $behaviour);
        app(CodingAgentManager::class)->extend($adapter, fn () => $agent);
    }

    /**
     * A behaviour that writes one file and completes.
     *
     * @return Closure(Workspace, AgentTask): AgentOutcome
     */
    protected function writes(string $adapter, string $provider, string $path, string $contents): Closure
    {
        return function (Workspace $workspace) use ($adapter, $provider, $path, $contents) {
            File::ensureDirectoryExists(dirname($this->path($workspace, $path)));
            File::put($this->path($workspace, $path), $contents);

            return $this->outcome($adapter, $provider, AgentOutcomeStatus::Completed, summary: 'Done.');
        };
    }

    protected function outcome(string $adapter, string $provider, AgentOutcomeStatus $status, ?string $summary = null, ?string $errorKind = null, ?string $error = null): AgentOutcome
    {
        return new AgentOutcome($adapter, $provider, null, $status, $summary, $errorKind, $error, turns: 2, inputTokens: 100, outputTokens: 50, costUsd: 0.01);
    }

    protected function path(?Workspace $workspace, string $path): string
    {
        return config('workspaces.drivers.local.root').DIRECTORY_SEPARATOR.$workspace?->driver_id.DIRECTORY_SEPARATOR.$path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function plan(): array
    {
        return [
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
        ];
    }

    protected function request(): FeatureRequest
    {
        $project = Project::factory()->create(['source_path' => $this->makeProjectSource()]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Give teams a description.']);
    }

    protected function passVerification(Run $run): void
    {
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => VerificationStatus::Passed, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'OK'],
        ], 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }
}
