<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\CancelRun;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\KeepTryingRun;
use App\Actions\Runs\StartRun;
use App\Actions\Runs\WriteBrief;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Ai\Agents\ChangeReviewer;
use App\Ai\Agents\FeaturePlanner;
use App\Context\ProjectNotes;
use App\Enums\AgentOutcomeStatus;
use App\Enums\AgentTier;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\VerificationStatus;
use App\Jobs\VerifyFeatureRequest;
use App\Models\Decision;
use App\Models\FeatureRequest;
use App\Models\ModelGatewayGrant;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\ModelGateway;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\WorkspaceManager;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
        ChangeReviewer::fake([['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => [
            ['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'teams have a description'],
        ]]]);
    }

    public function test_the_primary_agent_builds_the_change_and_the_other_provider_reviews_it()
    {
        $this->agent('claude', 'anthropic', function (Workspace $workspace) {
            File::put($this->path($workspace, 'app/Models/Team.php'), "<?php\n\nclass Team\n{\n    public ?string \$description = null;\n}\n");
            File::ensureDirectoryExists($this->path($workspace, 'tests/Feature'));
            File::put($this->path($workspace, 'tests/Feature/TeamTest.php'), "<?php\n\ntest('teams have a description', fn () => expect(true)->toBeTrue());\n");

            return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Completed, summary: 'Done.');
        });
        $this->agent('codex', 'openai', fn () => $this->fail('The failover agent must not run.'));

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('+    public ?string $description = null;', (string) $featureRequest->refresh()->patch);
        $this->assertStringContainsString("## Keep as it is\n\nDo not change these.", $this->agents['claude']->tasks[0]->prompt);
        // How to work goes apart from the task, for the gateway to add.
        $this->assertStringNotContainsString('## How to work', $this->agents['claude']->tasks[0]->prompt);
        $this->assertStringNotContainsString("## Write as the app's own developer", $this->agents['claude']->tasks[0]->prompt);
        $this->assertStringNotContainsString('Make it easy to see what the app does', $this->agents['claude']->tasks[0]->prompt);
        $this->assertStringContainsString("## Write as the app's own developer", (string) $this->agents['claude']->tasks[0]->instructions);
        $this->assertStringContainsString('## How to work', (string) $this->agents['claude']->tasks[0]->instructions);
        $this->assertStringContainsString('To add a package, run `composer require` or `npm install`', (string) $this->agents['claude']->tasks[0]->instructions);
        $this->assertSame(['claude'], $run->events()->where('type', 'model_call')->where('data->role', 'coder')->get()->pluck('data.adapter')->all());
        $this->assertSame(0, $run->events()->where('type', 'failover')->count());

        $this->passVerification($run);

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() === 'openai' && $prompt->model === 'openai-reviewer');
    }

    public function test_the_agent_runs_the_quick_checks_itself_before_it_finishes()
    {
        config([
            'builder.verification.checks' => [
                ['name' => 'Tests', 'command' => ['php', 'artisan', 'test'], 'timeout' => 600],
                ['name' => 'Static analysis', 'command' => ['vendor/bin/phpstan', 'analyse'], 'timeout' => 600],
            ],
            'builder.construction.self_checks' => ['Static analysis'],
        ]);
        $this->agent('claude', 'anthropic', fn () => $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Completed, summary: 'Done.'));

        app(StartRun::class)->handle($this->request());

        // A failure found after it finishes costs a whole repair pass.
        $rules = (string) $this->agents['claude']->tasks[0]->instructions;
        $this->assertStringContainsString('Before you finish, run `vendor/bin/phpstan analyse`, and fix anything reported.', $rules);
        $this->assertStringNotContainsString('`php artisan test`', $rules);
    }

    public function test_the_agent_is_not_asked_to_run_a_check_the_app_does_not_have()
    {
        config(['builder.construction.self_checks' => ['Nothing like this']]);
        $this->agent('claude', 'anthropic', fn () => $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Completed, summary: 'Done.'));

        app(StartRun::class)->handle($this->request());

        $this->assertStringNotContainsString('Before you finish, run', $this->agents['claude']->tasks[0]->prompt."\n\n".$this->agents['claude']->tasks[0]->instructions);
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

    public function test_when_the_other_provider_is_out_of_credit_the_default_reviewer_reviews_and_it_is_logged()
    {
        config(['builder.models.reviewer' => ['provider' => 'anthropic', 'model' => 'default-reviewer']]);
        ChangeReviewer::fake(fn (string $prompt, $attachments, Provider $provider) => $provider->name() === 'openai'
            ? throw InsufficientCreditsException::forProvider('openai')
            : ['approved' => true, 'summary' => 'Looks right.', 'findings' => [], 'changes' => [], 'verify' => [
                ['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'teams have a description'],
            ]]);
        $this->agent('claude', 'anthropic', $this->writes('claude', 'anthropic', 'app/Claude.php', "<?php\n"));

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->passVerification($run);

        ChangeReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() === 'anthropic' && $prompt->model === 'default-reviewer');
        $this->assertSame(['wanted' => 'openai', 'used' => 'anthropic'], $run->events()->where('type', 'reviewer_failed_over')->sole()->data);
    }

    public function test_a_failed_change_is_not_failed_over()
    {
        $this->agent('claude', 'anthropic', fn () => $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Failed, errorKind: 'error_during_execution', error: 'The tests would not pass.'));
        $this->agent('codex', 'openai', fn () => $this->fail('A failed change must not fail over.'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Failed, $run->status);
        // The owner hears whose fault it is; the agent's words stay with us.
        $this->assertSame('This is our fault: the AI stopped before it finished the change. Nothing in your app changed. Try again.', $run->error);
        $this->assertSame(['kind' => 'error_during_execution', 'error' => 'The tests would not pass.'], $run->events()->where('type', 'agent_failed')->sole()->data);
        $this->assertSame(0, $run->events()->where('type', 'failover')->count());
    }

    public function test_an_agent_whose_runner_restarted_starts_over_once_on_its_own()
    {
        $tries = 0;
        $halfLeft = null;
        $this->agent('claude', 'anthropic', function (Workspace $workspace, AgentTask $task) use (&$tries, &$halfLeft) {
            // The first try leaves a half-written file behind as it goes.
            if (++$tries === 1) {
                File::put($this->path($workspace, 'Half.php'), "<?php\n");

                return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Failed, errorKind: AgentOutcome::RUNNER_LOST, error: 'Lost.');
            }

            $halfLeft = File::exists($this->path($workspace, 'Half.php'));

            return ($this->writes('claude', 'anthropic', 'app/Claude.php', "<?php\n"))($workspace, $task);
        });
        $this->agent('codex', 'openai', fn () => $this->fail('A lost runner is not the agent\'s fault, so it does not fail over.'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(2, $tries);
        $this->assertNotSame(RunStatus::Failed, $run->status);
        $this->assertSame(['adapter' => 'claude'], $run->events()->where('type', 'runner_lost')->sole()->data);
        $this->assertFalse($halfLeft, 'The second try starts from where the first began.');
        $this->assertSame(0, $run->events()->where('type', 'failover')->count());
    }

    public function test_a_runner_lost_twice_stops_the_change_and_says_it_is_our_fault()
    {
        $tries = 0;
        $this->agent('claude', 'anthropic', function () use (&$tries) {
            $tries++;

            return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Failed, errorKind: AgentOutcome::RUNNER_LOST, error: 'Lost.');
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(2, $tries, 'It starts over once, not again and again.');
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('This is our fault: the computer working on your change restarted, so the AI could not finish. Nothing in your app changed. Try again.', $run->error);
        $this->assertSame(AgentOutcome::RUNNER_LOST, $run->events()->where('type', 'agent_failed')->sole()->data['kind']);
    }

    public function test_only_a_lost_runner_reads_as_one_and_a_real_timeout_still_says_timeout()
    {
        $timedOut = AgentOutcome::fromRunnerOutput('claude', 'anthropic', null, '', timedOut: true, lost: false);
        $lost = AgentOutcome::fromRunnerOutput('claude', 'anthropic', null, '', timedOut: true, lost: true);

        $this->assertSame(['timeout', 'The agent did not finish in time.'], [$timedOut->errorKind, $timedOut->error]);
        $this->assertSame(AgentOutcome::RUNNER_LOST, $lost->errorKind);

        // A timed-out agent is not started over, and the owner hears it stopped.
        $tries = 0;
        $this->agent('claude', 'anthropic', function () use (&$tries, $timedOut) {
            $tries++;

            return $timedOut;
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(1, $tries);
        $this->assertSame('This is our fault: the AI stopped before it finished the change. Nothing in your app changed. Try again.', $run->error);
        $this->assertSame(0, $run->events()->where('type', 'runner_lost')->count());
    }

    public function test_an_agent_that_breaks_before_its_first_turn_hands_the_change_to_the_other_agent()
    {
        $this->agent('claude', 'anthropic', fn () => new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Failed, null, 'exception', 'Exited with code 1: Reading prompt from stdin...'));
        $this->agent('codex', 'openai', $this->writes('codex', 'openai', 'app/Codex.php', "<?php\n"));

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('app/Codex.php', (string) $featureRequest->refresh()->patch);
        $this->assertSame(['from' => 'claude', 'to' => 'codex', 'reason' => 'exception'], $run->events()->where('type', 'failover')->sole()->data);
    }

    public function test_agents_do_not_run_beside_the_control_plane_unless_it_is_allowed()
    {
        config(['workspaces.drivers.local.agents' => false]);
        $this->agent('claude', 'anthropic', fn () => $this->fail('The agent must not run in the local driver.'));
        $this->agent('codex', 'openai', fn () => $this->fail('The agent must not run in the local driver.'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('This change could not be started here. Nothing in your app was changed.', $run->error);
        $this->assertSame(0, $run->events()->where('type', 'model_call')->where('data->role', 'coder')->count());
    }

    public function test_used_up_turns_stop_the_run_for_the_owner()
    {
        $this->agent('claude', 'anthropic', fn () => $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Failed, errorKind: 'error_max_turns'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('budget_exhausted', $run->events()->where('type', 'status')->get()->last()?->data['reason']);
    }

    public function test_a_change_out_of_turns_keeps_trying_in_the_same_session_when_the_owner_asks()
    {
        $this->agent('claude', 'anthropic', function (Workspace $workspace, AgentTask $task) {
            $first = count($this->agents['claude']->tasks) === 1;
            File::put($this->path($workspace, 'app/Team.php'), "<?php\n// half done\n");

            return new AgentOutcome('claude', 'anthropic', null, $first ? AgentOutcomeStatus::Failed : AgentOutcomeStatus::Completed, $first ? null : 'Done.', $first ? 'error_max_turns' : null, session: 'session-1', resumed: $task->resume !== null);
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertTrue(KeepTryingRun::possible($run->featureRequest));

        app(KeepTryingRun::class)->handle($run->featureRequest);

        // It goes on in the session that ran out, told only to finish.
        $again = $this->agents['claude']->tasks[1];
        $this->assertSame('session-1', $again->resume['session'] ?? null);
        $this->assertStringContainsString('You stopped before you finished. Finish the change.', $again->resume['prompt'] ?? '');
        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
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
            // How the app is checked is not the change's to decide.
            File::put($this->path($workspace, 'phpstan.neon'), "parameters:\n    level: 0\n");
            File::put($this->path($workspace, 'app/Real.php'), "<?php\n");

            return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Completed, summary: 'Done.');
        });

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $patch = (string) $featureRequest->refresh()->patch;
        $this->assertStringContainsString('app/Real.php', $patch);
        $this->assertStringNotContainsString('tests/Acceptance', $patch);
        $this->assertStringNotContainsString('phpstan.neon', $patch);

        // The repair is told which files were put back, so it leaves them alone.
        $restored = ['phpstan.neon', 'tests/Acceptance/Contract.php', 'tests/Acceptance/New.php'];
        $this->assertEqualsCanonicalizing($restored, $run->events()->where('type', 'protected_paths_restored')->sole()->data['paths']);
        $followUp = (string) app(WriteBrief::class)->followUp($run->forceFill(['feedback' => ['reason' => 'verification_failed', 'details' => ['A problem.']]]));
        $this->assertStringContainsString("## Files that were put back\n\n", $followUp);
        foreach ($restored as $path) {
            $this->assertStringContainsString("- {$path}", $followUp);
        }
    }

    public function test_work_the_agent_commits_itself_is_still_part_of_the_change()
    {
        $this->agent('claude', 'anthropic', function (Workspace $workspace) {
            $this->writes('claude', 'anthropic', 'app/First.php', "<?php // first\n")($workspace);
            $this->commitIn($workspace, 'Checkpoint');
            File::put($this->path($workspace, 'tests/Acceptance/Contract.php'), "<?php // weakened\n");
            File::put($this->path($workspace, 'tests/Acceptance/Added.php'), "<?php\n");
            $this->commitIn($workspace, 'Weaken the contract');

            return $this->writes('claude', 'anthropic', 'app/Second.php', "<?php // second\n")($workspace);
        });

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $patch = (string) $featureRequest->refresh()->patch;
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertStringContainsString('app/First.php', $patch);
        $this->assertStringContainsString('app/Second.php', $patch);
        $this->assertStringNotContainsString('tests/Acceptance', $patch);
        $this->assertFileDoesNotExist($this->path($run->workspace, 'tests/Acceptance/Added.php'));
        $this->assertSame(40, strlen((string) $run->workspace->baseline_commit));
    }

    public function test_the_lease_is_renewed_while_the_agent_works()
    {
        config(['builder.construction.heartbeat_seconds' => 0]);
        $this->agent('claude', 'anthropic', function (Workspace $workspace, AgentTask $task, ?Closure $whileRunning) {
            Run::query()->update(['lease_expires_at' => now()->addSeconds(5)]);

            $whileRunning();

            $this->assertTrue(Run::query()->sole()->lease_expires_at->isAfter(now()->addSeconds(200)));

            return $this->writes('claude', 'anthropic', 'app/Done.php', "<?php\n")($workspace);
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
    }

    public function test_a_worker_whose_run_is_taken_over_during_a_long_task_stops_its_agent()
    {
        config(['builder.construction.heartbeat_seconds' => 0]);
        $this->agent('claude', 'anthropic', function (Workspace $workspace, AgentTask $task, ?Closure $whileRunning) {
            // The task outlives the lease, and another worker takes the run.
            $run = Run::query()->sole();
            $run->update(['lease_expires_at' => now()->subSecond()]);
            app(AcquireRunLease::class)->handle($run, 'other-worker');

            $whileRunning();

            File::put($this->path($workspace, 'app/Late.php'), "<?php\n");

            return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Completed, summary: 'Done.');
        });

        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertSame('other-worker', $run->lease_owner);
        $this->assertNotSame(RunStatus::Verifying, $run->status);
        $this->assertFileDoesNotExist($this->path($run->workspace, 'app/Late.php'));
        $this->assertNull($featureRequest->refresh()->patch);
        $this->assertSame(0, $run->events()->where('type', 'model_call')->where('data->role', 'coder')->count());
    }

    public function test_cancelling_during_a_long_task_stops_the_agent()
    {
        config(['builder.construction.heartbeat_seconds' => 0]);
        $this->agent('claude', 'anthropic', function (Workspace $workspace, AgentTask $task, ?Closure $whileRunning) {
            app(CancelRun::class)->handle(Run::query()->sole());

            $whileRunning();

            File::put($this->path($workspace, 'app/Late.php'), "<?php\n");

            return $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Completed, summary: 'Done.');
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Cancelled, $run->status);
    }

    public function test_the_areas_come_from_the_request_and_the_agent_asks_for_another_by_reading_it()
    {
        // The planner sees one file only: the code of the area named comes first.
        config(['builder.construction.planning.max_files' => 1]);
        FeaturePlanner::fake([$this->plan()]);
        $featureRequest = $this->request();
        app(ProjectNotes::class)->put($featureRequest->project, 'main', [
            'project.md' => "# Project\n",
            'capabilities/teams.md' => "---\ncapability: teams\npaths: [app/Models/Team.php]\n---\n# Teams\n",
            'capabilities/billing.md' => "---\ncapability: billing\npaths: [config/billing.php]\n---\n# Billing\n",
        ]);
        $this->agent('claude', 'anthropic', function (Workspace $workspace) {
            File::put($this->path($workspace, 'app/Models/Team.php'), "<?php\n\nclass Team {}\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.', story: [
                ['kind' => 'read', 'file' => '.product-notes/capabilities/teams.md'],
                ['kind' => 'read', 'file' => 'config/billing.php'],
            ]);
        });

        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $compiled = $run->events()->where('type', 'context_compiled')->sole()->data;
        $this->assertSame(['teams' => 'named'], $compiled['chosen'], 'the request says "teams"; the planner chose nothing');
        $this->assertStringContainsString("Code in this area:\n- app/Models/Team.php", $run->context['text']);
        $this->assertSame(['billing' => 'config/billing.php'], $run->events()->where('type', 'areas_read')->sole()->data['areas']);
        FeaturePlanner::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, "## Project files\n\nEach line is a folder, then the files in it.\n\napp/Models/: Team.php"));
    }

    public function test_the_runner_agent_passes_the_task_and_credentials_and_reads_the_result_line()
    {
        config([
            'builder.agents.gateway.enabled' => false,
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
        $this->assertSame('model=claude-opus-5 turns='.config('builder.agents.max_turns').' key=present sandbox=none', $run->events()->where('type', 'build_finished')->sole()->data['account']);
        $this->assertStringContainsString('agent-output.txt', (string) $featureRequest->refresh()->patch);
        $this->assertStringNotContainsString('agent-task', (string) $featureRequest->patch);
        $this->assertSame([
            ['kind' => 'said', 'text' => 'I will write down the task first.'],
            ['kind' => 'changed', 'file' => 'agent-output.txt'],
        ], $run->events()->where('type', 'agent_story')->sole()->data['story'], 'Entries of an unknown kind are dropped.');
        $this->assertFalse(WorkspaceCommand::query()->get()->contains(fn (WorkspaceCommand $command) => str_contains((string) json_encode($command->command), 'test-anthropic-key')));
    }

    public function test_with_the_model_gateway_off_a_box_refuses_to_start_an_agent_with_the_real_key()
    {
        config(['ai.providers.anthropic.key' => 'test-anthropic-key', 'builder.agents.gateway.enabled' => false]);
        $workspace = Workspace::factory()->create(['driver' => 'docker']);
        $driver = Mockery::mock(WorkspaceDriver::class);
        // It refuses before anything reaches the box.
        $driver->shouldNotReceive('writeFile');
        $workspaces = Mockery::mock(WorkspaceManager::class);
        $workspaces->shouldReceive('driver')->with('docker')->andReturn($driver);
        $commands = Mockery::mock(RunWorkspaceCommand::class);
        $commands->shouldNotReceive('handle');
        $this->app->instance(WorkspaceManager::class, $workspaces);
        $this->app->instance(RunWorkspaceCommand::class, $commands);

        try {
            app(CodingAgentManager::class)->driver('claude')->run($workspace, new AgentTask('Add teams.', instructions: '## How to work'));
            $this->fail('The agent started with the real key.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('The model gateway is off', $exception->getMessage());
        }

        $this->assertSame(0, ModelGatewayGrant::query()->count());
    }

    public function test_through_the_gateway_the_box_gets_only_the_task_and_the_gateway_keeps_our_rules()
    {
        config([
            'ai.providers.anthropic.key' => 'test-anthropic-key',
            'builder.agents.gateway.enabled' => true,
            'builder.agents.gateway.url' => 'http://control-plane.test',
        ]);
        $workspace = Workspace::factory()->create(['driver' => 'docker']);
        $driver = Mockery::mock(WorkspaceDriver::class);
        $driver->shouldReceive('writeFile')->once()->withArgs(function (string $id, string $path, string $contents) use (&$task) {
            $task = json_decode($contents, true);

            return true;
        });
        $workspaces = Mockery::mock(WorkspaceManager::class);
        $workspaces->shouldReceive('driver')->with('docker')->andReturn($driver);
        $commands = Mockery::mock(RunWorkspaceCommand::class);
        $commands->shouldReceive('handle')->andReturnUsing(fn (Workspace $box, array $command) => new WorkspaceCommand([
            'command' => $command,
            'exit_code' => 0,
            'timed_out' => false,
            'lost' => false,
            'duration_ms' => 5,
            'output' => json_encode(['type' => 'result', 'adapter' => 'claude', 'status' => 'completed', 'summary' => 'Done.', 'turns' => 1, 'session' => 'fake-session'])."\n",
            'error_output' => '',
        ]));
        $this->app->instance(WorkspaceManager::class, $workspaces);
        $this->app->instance(RunWorkspaceCommand::class, $commands);

        $outcome = app(CodingAgentManager::class)->driver('claude')->run($workspace, new AgentTask(
            'Add teams. Use sk_live_'.str_repeat('x', 24).' for payments.',
            instructions: "## How to work\n\nFollow the app's AGENTS.md.",
        ));

        $this->assertSame(AgentOutcomeStatus::Completed, $outcome->status);
        // The agent is told which files it may not change, never sees a key
        // the owner pasted, and never holds our rules.
        $this->assertContains('tests/Acceptance', $task['protected_paths']);
        $this->assertSame('Add teams. Use [secret removed] for payments.', $task['prompt']);
        $this->assertStringNotContainsString('How to work', (string) json_encode($task));
        $grant = ModelGatewayGrant::query()->sole();
        $this->assertSame("## How to work\n\nFollow the app's AGENTS.md.", $grant->instructions);
        $this->assertNotNull($grant->closed_at);
    }

    public function test_with_the_model_gateway_on_the_agent_gets_a_run_token_and_never_the_key()
    {
        config([
            'ai.providers.anthropic.key' => 'test-anthropic-key',
            'builder.agents.runner.path' => base_path('tests/Fixtures/gateway-agent-runner.mjs'),
            'builder.agents.gateway.enabled' => true,
            'builder.agents.gateway.url' => 'http://control-plane.test',
        ]);
        FeaturePlanner::fake([$this->plan()]);
        $this->app->instance(ModelGateway::class, $gateway = new class extends ModelGateway
        {
            /** @var list<string> */
            public array $tokens = [];

            public function open(string $provider, int $seconds, ?string $instructions = null, ?User $owner = null): array
            {
                $opened = parent::open($provider, $seconds, $instructions, $owner);
                $this->tokens[] = $opened['token'];

                return $opened;
            }
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame('key=gateway url=http://control-plane.test/api/gateway/anthropic', $run->events()->where('type', 'build_finished')->sole()->data['account']);
        $this->assertFalse(WorkspaceCommand::query()->get()->contains(fn (WorkspaceCommand $command) => str_contains((string) json_encode($command->command), 'test-anthropic-key')));
        $this->assertCount(1, $gateway->tokens);
        $this->assertNull($gateway->grant($gateway->tokens[0]), 'The token is closed once the run ends.');
        $this->assertStringContainsString('## How to work', (string) $gateway->instructions($gateway->tokens[0]));
        // The run spends its owner's monthly AI use.
        $this->assertSame($run->featureRequest->project->owner->id, ModelGatewayGrant::query()->sole()->user_id);
    }

    /**
     * @return array<string, array{bool, bool, string}>
     */
    public static function endsAfterARefusal(): array
    {
        $result = json_encode(['type' => 'result', 'adapter' => 'claude', 'status' => 'failed', 'error_kind' => 'error_during_execution', 'turns' => 3, 'cost_usd' => 0.4])."\n";

        return [
            'the agent gives up' => [false, false, $result],
            'the agent waits until it times out' => [true, false, ''],
            'the runner goes away' => [false, true, ''],
        ];
    }

    #[DataProvider('endsAfterARefusal')]
    public function test_a_call_the_gateway_refused_marks_the_agents_outcome_as_stopped_by_the_plan(bool $timedOut, bool $lost, string $output)
    {
        config([
            'ai.providers.anthropic.key' => 'test-anthropic-key',
            'builder.agents.gateway.enabled' => true,
            'builder.agents.gateway.url' => 'http://control-plane.test',
            'billing.plans.free.monthly_usd' => 5,
        ]);
        $workspace = Workspace::factory()->create(['driver' => 'docker']);
        $driver = Mockery::mock(WorkspaceDriver::class);
        $driver->shouldReceive('writeFile')->once();
        $workspaces = Mockery::mock(WorkspaceManager::class);
        $workspaces->shouldReceive('driver')->with('docker')->andReturn($driver);
        $commands = Mockery::mock(RunWorkspaceCommand::class);
        $commands->shouldReceive('handle')->andReturnUsing(function (Workspace $box, array $command) use ($timedOut, $lost, $output) {
            // While the agent works, the gateway refuses one of its calls,
            // and the agent ends however it takes that.
            ModelGatewayGrant::query()->sole()->forceFill(['cost_usd' => 5, 'refused_at' => now()])->save();

            return new WorkspaceCommand([
                'command' => $command,
                'exit_code' => $output === '' ? 1 : 0,
                'timed_out' => $timedOut,
                'lost' => $lost,
                'duration_ms' => 5,
                'output' => $output,
                'error_output' => '',
            ]);
        });
        $this->app->instance(WorkspaceManager::class, $workspaces);
        $this->app->instance(RunWorkspaceCommand::class, $commands);

        $outcome = app(CodingAgentManager::class)->driver('claude')->run($workspace, new AgentTask('Add teams.', owner: User::factory()->create()));

        // Not a lost runner, a timeout or the agent's own failure: the plan.
        $this->assertSame([AgentOutcomeStatus::Failed, AgentOutcome::USAGE_LIMIT], [$outcome->status, $outcome->errorKind]);
        $this->assertSame($output === '' ? [0, null] : [3, 0.4], [$outcome->turns, $outcome->costUsd], 'What it spent is kept.');
        $this->assertSame('5.000000', ModelGatewayGrant::query()->sole()->allowance_usd);
    }

    public function test_an_agent_stopped_by_the_plan_stops_the_run_for_the_plan_without_failing_over()
    {
        $this->agent('claude', 'anthropic', fn () => $this->outcome('claude', 'anthropic', AgentOutcomeStatus::Failed, errorKind: AgentOutcome::USAGE_LIMIT, error: 'Refused.'));
        $this->agent('codex', 'openai', fn () => $this->fail('Another agent must not spend past the plan.'));

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(StopReason::UsageLimit, $run->stop_reason);
        // It reads as the plan running out, not as our fault.
        $this->assertMatchesRegularExpression('/^You have used all the AI use your plan includes this month\. It starts again on \d{1,2} \w+, or you can move to a bigger plan in Settings\. Nothing in your app changed\.$/', (string) $run->error);
        $this->assertStringNotContainsString('our fault', (string) $run->error);
        $this->assertSame(0, $run->events()->where('type', 'failover')->count());
        $this->assertSame(0.01, $run->events()->where('type', 'model_call')->where('data->role', 'coder')->sole()->data['cost_usd']);
    }

    public function test_an_agent_that_reports_no_cost_is_priced_from_config_only_when_its_model_is_known()
    {
        config(['builder.agents.order' => ['codex'], 'builder.prices' => ['codex-model' => ['input' => 2, 'output' => 8]]]);
        $model = 'codex-model';
        $this->agent('codex', 'openai', function (Workspace $workspace) use (&$model) {
            File::put($this->path($workspace, 'app/Codex.php'), "<?php\n");

            return new AgentOutcome('codex', 'openai', $model, AgentOutcomeStatus::Completed, 'Done.', turns: 1, inputTokens: 1_000_000, outputTokens: 100_000);
        });
        $cost = function () {
            FeaturePlanner::fake([$this->plan()]);
            $call = app(StartRun::class)->handle($this->request())->refresh()->events()->where('type', 'model_call')->where('data->role', 'coder')->sole()->data;

            return [$call['cost_usd'], $call['cost_source']];
        };

        $this->assertSame([2.8, 'estimated'], $cost());

        // With no model named, the agent's default is unknown, and so is the cost.
        $model = null;
        $this->assertSame([null, null], $cost());
    }

    public function test_input_the_agent_read_from_the_providers_cache_is_priced_as_cached()
    {
        config(['builder.agents.order' => ['codex'], 'builder.prices' => ['codex-model' => ['input' => 2, 'output' => 8]]]);
        $this->agent('codex', 'openai', function (Workspace $workspace) {
            File::put($this->path($workspace, 'app/Codex.php'), "<?php\n");

            return new AgentOutcome('codex', 'openai', 'codex-model', AgentOutcomeStatus::Completed, 'Done.', turns: 1, inputTokens: 1_000_000, outputTokens: 100_000, cachedInputTokens: 900_000);
        });
        $cost = function () {
            FeaturePlanner::fake([$this->plan()]);

            return app(StartRun::class)->handle($this->request())->refresh()->events()->where('type', 'model_call')->where('data->role', 'coder')->sole()->data;
        };

        // Without a cached price, cached input costs as much as fresh input.
        $this->assertSame(2.8, $cost()['cost_usd']);

        config(['builder.prices.codex-model.cached_input' => 0.2]);
        $call = $cost();
        $this->assertSame(1.18, $call['cost_usd']);
        $this->assertSame(900_000, $call['cached_input_tokens']);
    }

    public function test_a_background_tidy_up_goes_to_the_light_model_and_other_changes_to_the_usual_one()
    {
        config([
            'ai.providers.anthropic.key' => 'test-anthropic-key',
            'builder.agents.runner.path' => base_path('tests/Fixtures/fake-agent-runner.mjs'),
            'builder.agents.adapters.claude.model' => 'claude-opus-5',
            'builder.agents.adapters.claude.light_model' => 'claude-haiku-5',
        ]);
        $account = function (?array $tidy) {
            FeaturePlanner::fake([$this->plan()]);
            $request = $this->request();
            $request->update(['tidy' => $tidy]);

            return app(StartRun::class)->handle($request)->refresh()->events()->where('type', 'build_finished')->sole()->data['account'];
        };

        $this->assertStringStartsWith('model=claude-haiku-5 ', $account(['of' => 1, 'tier' => 'light', 'shortcuts' => []]));
        $this->assertStringStartsWith('model=claude-opus-5 ', $account(['of' => 1, 'tier' => 'full', 'shortcuts' => []]));
        $this->assertStringStartsWith('model=claude-opus-5 ', $account(null));
    }

    public function test_a_request_the_decision_model_is_sure_is_trivial_is_first_built_by_the_light_model_once_switched_on()
    {
        config([
            'ai.providers.anthropic.key' => 'test-anthropic-key',
            'builder.agents.runner.path' => base_path('tests/Fixtures/fake-agent-runner.mjs'),
            'builder.agents.adapters.claude.model' => 'claude-opus-5',
            'builder.agents.adapters.claude.light_model' => 'claude-haiku-5',
        ]);
        $build = function (string $choice, array $act) {
            config(['builder.decisions.act' => $act]);
            FeaturePlanner::fake([$this->plan()]);
            $request = $this->request();
            Decision::factory()->for($request)->create(['name' => 'complexity', 'choice' => $choice, 'confidence' => 0.95, 'threshold' => 0.9]);
            $run = app(StartRun::class)->handle($request)->refresh();

            return [$run->events()->where('type', 'build_finished')->sole()->data['account'], $run->events()->where('type', 'decision_acted')->count()];
        };

        [$account, $acted] = $build('trivial', ['complexity']);
        $this->assertStringStartsWith('model=claude-haiku-5 ', $account);
        $this->assertSame(1, $acted);

        // Switched off, which is the default, or not trivial: the usual model.
        $this->assertStringStartsWith('model=claude-opus-5 ', $build('trivial', [])[0]);
        $this->assertStringStartsWith('model=claude-opus-5 ', $build('normal', ['complexity'])[0]);
    }

    public function test_a_repair_on_the_strong_model_is_run_and_recorded_as_the_strong_model()
    {
        config([
            'ai.providers.anthropic.key' => 'test-anthropic-key',
            'builder.agents.order' => ['claude'],
            'builder.agents.runner.path' => base_path('tests/Fixtures/fake-agent-runner.mjs'),
            'builder.agents.adapters.claude.model' => 'claude-opus-5',
            'builder.agents.adapters.claude.strong_model' => 'claude-strong-5',
            'builder.agents.adapters.claude.strong_effort' => 'max',
            'builder.construction.budgets.repairs' => 5,
        ]);
        FeaturePlanner::fake([$this->plan()]);

        $run = app(StartRun::class)->handle($this->request())->refresh();
        foreach (range(1, 3) as $repair) {
            $this->failVerification($run->refresh(), "Expected description to be fillable ({$repair}).");
        }

        $accounts = $run->events()->where('type', 'build_finished')->orderBy('sequence')->get()->map(fn (RunEvent $event) => $event->data['account'])->all();
        $this->assertStringStartsWith('model=claude-opus-5 ', $accounts[2]);
        $this->assertStringStartsWith('model=claude-strong-5 ', $accounts[3]);
        $this->assertStringEndsWith('effort=max', $accounts[3]);
        $calls = $run->events()->where('type', 'model_call')->where('data->role', 'coder')->orderBy('sequence')->get()->map(fn (RunEvent $event) => [$event->data['model'], $event->data['tier']])->all();
        $this->assertSame([['claude-opus-5', 'usual'], ['claude-strong-5', 'strong']], array_slice($calls, 2));
    }

    public function test_the_strong_model_s_work_is_priced_at_its_own_rate_or_left_unpriced()
    {
        config(['builder.agents.order' => ['codex'], 'builder.agents.adapters.codex.strong_model' => 'codex-strong', 'builder.construction.budgets.repairs' => 5]);
        $this->agent('codex', 'openai', function (Workspace $workspace, AgentTask $task) {
            File::put($this->path($workspace, 'app/Codex.php'), "<?php\n// ".count($this->agents['codex']->tasks)."\n");

            return new AgentOutcome('codex', 'openai', $task->tier === AgentTier::Strong ? 'codex-strong' : 'codex-model', AgentOutcomeStatus::Completed, 'Done.', turns: 1, inputTokens: 100_000, outputTokens: 10_000);
        });
        $costs = function (array $prices) {
            config(['builder.prices' => $prices]);
            FeaturePlanner::fake([$this->plan()]);
            $run = app(StartRun::class)->handle($this->request())->refresh();
            foreach (range(1, 3) as $repair) {
                $this->failVerification($run->refresh(), "Expected description to be fillable ({$repair}).");
            }

            return $run->events()->where('type', 'model_call')->where('data->role', 'coder')->orderBy('sequence')->get()->map(fn (RunEvent $event) => $event->data['cost_usd'])->all();
        };

        $this->assertSame([0.28, 0.28, 0.28, 1.4], $costs(['codex-model' => ['input' => 2, 'output' => 8], 'codex-strong' => ['input' => 10, 'output' => 40]]));
        // With no price for the strong model, its work is unpriced, not free
        // and not counted at the usual model's rate.
        $this->assertSame([0.28, 0.28, 0.28, null], $costs(['codex-model' => ['input' => 2, 'output' => 8]]));
    }

    public function test_a_resumed_claude_session_costs_only_what_it_added()
    {
        // Claude reports what the whole session has cost so far.
        $totals = [2.27, 2.49, 2.58];
        $this->agent('claude', 'anthropic', function (Workspace $workspace, AgentTask $task) use (&$totals) {
            File::put($this->path($workspace, 'app/Team.php'), "<?php\n// ".count($this->agents['claude']->tasks)."\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.', costUsd: array_shift($totals), session: 'session-1', resumed: $task->resume !== null);
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->failVerification($run, 'Expected description to be fillable.');
        $this->failVerification($run->refresh(), 'Expected description to be required.');

        $calls = $run->events()->where('type', 'model_call')->where('data->role', 'coder')->orderBy('sequence')->get();
        $this->assertSame([2.27, 0.22, 0.09], $calls->map(fn (RunEvent $event) => $event->data['cost_usd'])->all());
        $this->assertSame([2.27, 2.49, 2.58], $calls->map(fn (RunEvent $event) => $event->data['session_cost_usd'])->all());
    }

    public function test_a_repair_pass_continues_the_agents_session_with_only_the_problems()
    {
        $this->agent('claude', 'anthropic', function (Workspace $workspace, AgentTask $task) {
            File::put($this->path($workspace, 'app/Team.php'), "<?php\n// ".count($this->agents['claude']->tasks)."\n");

            return new AgentOutcome('claude', 'anthropic', null, AgentOutcomeStatus::Completed, 'Done.', session: 'session-'.count($this->agents['claude']->tasks), resumed: $task->resume !== null);
        });

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->failVerification($run, 'Expected description to be fillable.');

        [$first, $repair] = $this->agents['claude']->tasks;
        $this->assertNull($first->resume);
        $this->assertSame(['claude', 'session-1'], [$repair->resume['adapter'] ?? null, $repair->resume['session'] ?? null]);
        $this->assertStringContainsString('Expected description to be fillable.', $repair->resume['prompt']);
        $this->assertStringNotContainsString("Owner's request", $repair->resume['prompt'], 'The session already has the brief.');
        // Should the session be gone, the agent starts fresh with everything.
        $this->assertStringContainsString("Owner's request", $repair->prompt);
        $this->assertStringContainsString('Expected description to be fillable.', $repair->prompt);
        $this->assertSame([false, true], $run->events()->where('type', 'model_call')->where('data->role', 'coder')->orderBy('sequence')->get()->map(fn (RunEvent $event) => $event->data['resumed'])->all());

        // A later repair continues the latest session, not the first.
        $this->failVerification($run->refresh(), 'Expected description to be required.');
        $this->assertSame('session-2', $this->agents['claude']->tasks[2]->resume['session'] ?? null);
    }

    public function test_the_runner_continues_only_its_own_agents_session_and_passes_the_effort()
    {
        config([
            'builder.agents.order' => ['codex'],
            'builder.agents.runner.path' => base_path('tests/Fixtures/fake-agent-runner.mjs'),
            'builder.agents.adapters.codex.effort' => 'medium',
        ]);
        FeaturePlanner::fake([$this->plan()]);
        $run = app(StartRun::class)->handle($featureRequest = $this->request())->refresh();

        $this->assertStringEndsWith('effort=medium', $run->events()->where('type', 'build_finished')->sole()->data['account']);

        $this->failVerification($run, 'Expected description to be fillable.');

        $calls = $run->events()->where('type', 'model_call')->where('data->role', 'coder')->orderBy('sequence')->get()->map(fn (RunEvent $event) => [$event->data['session'], $event->data['resumed']])->all();
        $this->assertSame([['fake-session', false], ['fake-session', true]], $calls);
        $this->assertStringNotContainsString("Owner's request", (string) $featureRequest->refresh()->patch, 'The continued session was sent only the problems.');

        // A session made by another agent is not continued.
        $agent = app(CodingAgentManager::class)->driver('codex');
        $workspace = $run->refresh()->workspace;
        $outcome = $agent->run($workspace, new AgentTask('Whole prompt', resume: ['adapter' => 'claude', 'session' => 'other', 'prompt' => 'Only this']));
        $this->assertFalse($outcome->resumed);
        $this->assertSame('Whole prompt', File::get($this->path($workspace, 'agent-output.txt')));
    }

    public function test_codex_can_work_on_its_own_sign_in_instead_of_the_api_key()
    {
        config([
            'ai.providers.openai.key' => 'test-openai-key',
            'builder.agents.order' => ['codex'],
            'builder.agents.runner.path' => base_path('tests/Fixtures/fake-agent-runner.mjs'),
            'builder.agents.adapters.codex.use_api_key' => false,
        ]);
        FeaturePlanner::fake([$this->plan()]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertStringContainsString('key=missing', $run->events()->where('type', 'build_finished')->sole()->data['account']);
    }

    public function test_the_brief_holds_nothing_of_ours_that_would_do_harm_when_read()
    {
        config(['builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model-7'], 'builder.agents.adapters.claude.model' => 'coder-model-7']);
        $this->agent('claude', 'anthropic', $this->writes('claude', 'anthropic', 'app/Team.php', "<?php\n"));

        $run = app(StartRun::class)->handle($this->request())->refresh();
        $this->failVerification($run, 'Expected description to be fillable.');

        // Whoever runs the worker can read all of it (architecture §11).
        foreach ([$this->agents['claude']->tasks[1]->prompt, (string) app(WriteBrief::class)->followUp($run->refresh()->forceFill(['feedback' => ['reason' => 'verification_failed', 'details' => ['A problem.']]]))] as $brief) {
            $this->assertDoesNotMatchRegularExpression('/\b(builder|platform|control plane|confidence|probabilit\w*|scores?|capabilit\w*|context compiler|planner|anthropic|openai)\b/i', $brief);
            $this->assertDoesNotMatchRegularExpression('/\bEffects?\b/', $brief);
            $this->assertStringNotContainsString('planner-model-7', $brief);
            $this->assertStringNotContainsString('coder-model-7', $brief);
        }
    }

    public function test_the_codex_agent_gets_its_configured_sandbox()
    {
        config([
            'builder.agents.order' => ['codex'],
            'builder.agents.runner.path' => base_path('tests/Fixtures/fake-agent-runner.mjs'),
            'builder.agents.adapters.codex.sandbox' => 'danger-full-access',
        ]);
        FeaturePlanner::fake([$this->plan()]);

        $run = app(StartRun::class)->handle($this->request())->refresh();

        $this->assertStringEndsWith('sandbox=danger-full-access', $run->events()->where('type', 'build_finished')->sole()->data['account']);
    }

    /**
     * Register a scripted agent.
     *
     * @param  Closure(Workspace, AgentTask, (Closure(): void)|null): AgentOutcome  $behaviour
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

    protected function commitIn(Workspace $workspace, string $message): void
    {
        Process::path($this->path($workspace, ''))->run(['git', 'add', '--all'])->throw();
        Process::path($this->path($workspace, ''))->run(['git', '-c', 'user.name=Agent', '-c', 'user.email=agent@example.com', '-c', 'commit.gpgsign=false', 'commit', '-qm', $message])->throw();
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
            'cases' => [['base' => 'A team saved with a description keeps it.', 'alternate' => null, 'no_alternate' => 'A description is only set one way.', 'exception' => null, 'no_exception' => 'Nothing about a description is refused.']],
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

    /**
     * Record a failing verification for the run's change and carry it back.
     */
    protected function failVerification(Run $run, string $output): void
    {
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => VerificationStatus::Failed, 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => $output],
        ], 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }
}
