<?php

namespace Tests\Feature\Operations;

use App\Actions\Runs\DescribeRunProgress;
use App\Actions\Runs\RecordModelUsage;
use App\Actions\Runs\StartRun;
use App\Actions\Runs\TransitionRun;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\ModelRole;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\WorkspaceStatus;
use App\Jobs\ExecuteRun;
use App\Models\ExecutionConfig;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\WorkerHeartbeat;
use App\Models\Workspace;
use App\Operations\WorkerPulse;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Mockery;
use RuntimeException;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class OperationsFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_records_the_settings_it_was_built_with_and_never_credentials()
    {
        Queue::fake();
        config([
            'ai.providers.openai.key' => 'sk-live-do-not-store',
            'builder.models.planner.model' => 'planner-model-1',
            'workspaces.drivers.runner.provider' => 'docker',
        ]);

        $run = app(StartRun::class)->handle(FeatureRequest::factory()->create());
        $settings = ExecutionConfig::query()->findOrFail($run->config_version);

        $this->assertSame(hash('sha256', (string) json_encode($settings->settings)), $run->config_version);
        $this->assertSame('planner-model-1', $settings->settings['builder.models.planner.model']);
        $this->assertSame('docker', $settings->settings['workspaces.drivers.runner.provider']);
        $this->assertStringNotContainsString('sk-live', (string) json_encode($settings->settings));
        $this->assertSame($run->config_version, $run->events()->where('type', 'created')->value('data')['config_version']);

        // The same settings are stored once, whatever the number of runs.
        app(StartRun::class)->handle(FeatureRequest::factory()->create());
        $this->assertSame(1, ExecutionConfig::query()->count());
    }

    public function test_a_run_keeps_why_it_stopped_until_it_moves_on()
    {
        $run = Run::factory()->implementing()->create();
        $transition = app(TransitionRun::class);

        $transition->handle($run, RunStatus::NeedsUserDecision, details: ['reason' => StopReason::BudgetExhausted]);
        $this->assertSame(StopReason::BudgetExhausted, $run->fresh()?->stop_reason);

        $transition->handle($run, RunStatus::Implementing);
        $this->assertNull($run->fresh()?->stop_reason);

        // A stop always says why: there is no unknown one.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A run that moves to failed needs a StopReason as its reason.');
        $transition->handle($run, RunStatus::Failed);
    }

    public function test_a_worker_that_died_on_its_last_try_is_recorded_as_such()
    {
        $run = Run::factory()->implementing()->create();

        (new ExecuteRun($run))->failed(new RuntimeException('killed'));

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame(StopReason::WorkerStopped, $run->stop_reason);
        $this->assertSame('worker_stopped', $run->events()->where('type', 'status')->reorder('sequence', 'desc')->value('data')['reason']);
    }

    public function test_a_review_that_stops_is_reviewed_again_before_the_run_fails()
    {
        config(['builder.construction.budgets.review_restarts' => 2]);
        $run = Run::factory()->create(['status' => RunStatus::Reviewing]);

        // The change passed its checks: twice it waits to be reviewed again.
        foreach ([1, 2] as $stopped) {
            (new ExecuteRun($run))->failed(new RuntimeException('reviewer down'));

            $this->assertSame(RunStatus::Reviewing, $run->refresh()->status);
            $this->assertSame($stopped, $run->events()->where('type', 'review_stopped')->count());
        }

        $this->assertStringStartsWith('This is our fault', app(DescribeRunProgress::class)->handle($run)['text'] ?? '');

        (new ExecuteRun($run))->failed(new RuntimeException('reviewer down'));

        $this->assertSame(RunStatus::Failed, $run->refresh()->status);
        $this->assertSame(StopReason::WorkerStopped, $run->stop_reason);
        // The owner hears that the change passed its checks, not only that
        // something stopped.
        $this->assertStringContainsString('passed its checks', (string) $run->error);
    }

    public function test_a_model_call_is_counted_once_however_often_a_retried_job_records_it()
    {
        config(['builder.prices' => ['m' => ['input' => 1, 'output' => 1]]]);
        $run = Run::factory()->implementing()->create();
        $response = new AgentResponse('call-1', 'plan', new TextUsage(1000, 100), new Meta('anthropic', 'm'));

        app(RecordModelUsage::class)->handle($run, ModelRole::Planner, $response);
        app(RecordModelUsage::class)->handle($run, ModelRole::Planner, $response);

        $calls = $run->events()->where('type', 'model_call')->get();
        $this->assertCount(1, $calls);
        $this->assertSame('estimated', $calls->first()?->data['cost_source']);
    }

    public function test_a_workspace_that_could_not_be_removed_says_so()
    {
        $driver = new class extends FakeWorkspaceDriver
        {
            public function destroy(string $workspaceId): void
            {
                throw new RuntimeException('box service unreachable');
            }
        };
        $this->app->make(WorkspaceManager::class)->extend('broken', fn () => $driver);
        $workspace = Workspace::factory()->create(['driver' => 'broken']);

        try {
            app(DestroyWorkspace::class)->handle($workspace);
            $this->fail('The failure was swallowed.');
        } catch (RuntimeException) {
        }

        $workspace->refresh();
        $this->assertSame(WorkspaceStatus::Ready, $workspace->status);
        $this->assertNotNull($workspace->cleanup_failed_at);
        $this->assertSame('box service unreachable', $workspace->cleanup_error);
    }

    public function test_only_a_real_worker_writes_a_heartbeat()
    {
        $job = Mockery::mock(Job::class);
        $job->allows(['resolveName' => 'App\\Jobs\\ExecuteRun', 'timeout' => 3600, 'payload' => []]);

        // A job run inline, with no worker loop, is not a worker.
        event(new JobProcessing('sync', $job));
        $this->assertSame(0, WorkerHeartbeat::query()->count());

        event(new Looping('redis', 'default,previews'));
        $heartbeat = WorkerHeartbeat::query()->findOrFail(WorkerPulse::name());
        $this->assertSame(['default', 'previews'], $heartbeat->queueNames());
        $this->assertNull($heartbeat->job);

        event(new JobProcessing('redis', $job));
        $heartbeat->refresh();
        $this->assertSame('App\\Jobs\\ExecuteRun', $heartbeat->job);
        $this->assertSame(3600, $heartbeat->job_timeout);

        // A long job keeps the worker alive past the idle window.
        $this->travel(10)->minutes();
        $this->assertTrue($heartbeat->alive(now()->toImmutable()));

        event(new JobProcessed('redis', $job));
        $this->assertNull($heartbeat->refresh()->job);

        event(new WorkerStopping(0));
        $this->assertSame(0, WorkerHeartbeat::query()->count());
    }

    public function test_a_worker_silent_past_the_window_is_not_alive()
    {
        $heartbeat = WorkerHeartbeat::query()->create(['worker' => 'host:1', 'connection' => 'redis', 'queues' => 'default', 'last_seen_at' => now()->subMinutes(5)]);

        $this->assertFalse($heartbeat->alive(now()->toImmutable()));
    }
}
