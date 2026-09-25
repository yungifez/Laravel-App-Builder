<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\CancelRun;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\StartRun;
use App\Actions\Runs\TransitionRun;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\ExecuteRun;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Runs\BuiltChange;
use App\Runs\ConstructionDriverManager;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Exceptions\InvalidRunTransition;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunLeaseHeld;
use App\Runs\ToolSession;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\Concerns\UsesReferenceSolutions;
use Tests\TestCase;

class RunLifecycleTest extends TestCase
{
    use PreparesRuns, RefreshDatabase, UsesReferenceSolutions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();
    }

    public function test_a_run_builds_the_change_through_the_tools_and_hands_it_to_verification()
    {
        $featureRequest = $this->invitationRequest();

        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(1, $run->fencing_token);
        $this->assertSame(1, $run->workspace_revision);
        $this->assertNull($run->lease_owner);
        $this->assertSame(
            [['scripted:list-files', 'list_files', 'succeeded'], ['scripted:apply-patch', 'apply_patch', 'succeeded']],
            $run->operations()->orderBy('id')->get()->map(fn ($operation) => [$operation->operation_key, $operation->tool, $operation->status->value])->all(),
        );
        $this->assertSame(
            ['created', 'lease_acquired', 'status', 'status', 'workspace_ready', 'operation', 'operation', 'status'],
            $run->events()->pluck('type')->all(),
        );
        $this->assertSame(range(1, 8), $run->events()->pluck('sequence')->all());

        $featureRequest->refresh();
        $this->assertSame(FeatureRequestStatus::Generated, $featureRequest->status);
        $this->assertStringContainsString("+        'members:invite',", (string) $featureRequest->patch);
        $this->assertSame(VerificationStatus::Queued, $run->verifications()->sole()->status);
        Queue::assertPushed(VerifyFeatureRequest::class, 1);
    }

    public function test_a_duplicate_delivery_does_not_repeat_the_work()
    {
        $featureRequest = $this->invitationRequest();
        $run = app(StartRun::class)->handle($featureRequest);

        ExecuteRun::dispatchSync($run->refresh());

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(2, $run->operations()->count());
        $this->assertSame(1, $run->verifications()->count());
        $this->assertSame(1, $run->events()->where('type', 'lease_acquired')->count());
    }

    public function test_a_duplicate_delivery_leaves_a_run_another_worker_holds_alone()
    {
        $featureRequest = $this->invitationRequest();
        $run = Run::factory()->for($featureRequest)->create();
        $lease = app(AcquireRunLease::class)->handle($run, 'worker-a');

        ExecuteRun::dispatchSync($run);

        $run->refresh();
        $this->assertSame(RunStatus::Queued, $run->status);
        $this->assertSame($lease?->fencingToken, $run->fencing_token);
        $this->assertSame('worker-a', $run->lease_owner);
        $this->assertSame(0, $run->operations()->count());

        $this->expectException(RunLeaseHeld::class);
        app(AcquireRunLease::class)->handle($run, 'worker-b');
    }

    public function test_a_run_whose_worker_died_is_resumed_from_its_journal()
    {
        $featureRequest = $this->invitationRequest();
        $run = Run::factory()->for($featureRequest)->create();
        $firstLease = app(AcquireRunLease::class)->handle($run, 'worker-a');
        app(TransitionRun::class)->handle($run, RunStatus::Planning, $firstLease);
        app(TransitionRun::class)->handle($run, RunStatus::Implementing, $firstLease);

        // The worker dies here; its lease runs out and the reconciler resumes the run.
        $this->travel(config('builder.construction.lease_seconds') + 1)->seconds();
        Queue::fake([VerifyFeatureRequest::class, ExecuteRun::class]);

        $this->artisan('runs:reconcile')->assertSuccessful();

        Queue::assertPushed(ExecuteRun::class, fn (ExecuteRun $job) => $job->run->is($run));
        app()->call([new ExecuteRun($run), 'handle']);

        $run->refresh();
        $this->assertSame(RunStatus::Verifying, $run->status);
        $this->assertSame(2, $run->fencing_token);
        $this->assertTrue($run->events()->where('type', 'lease_acquired')->get()->last()->data['took_over']);

        $this->expectException(LeaseLost::class);
        app(TransitionRun::class)->handle($run, RunStatus::Failed, $firstLease);
    }

    public function test_the_state_machine_refuses_invalid_transitions()
    {
        $run = Run::factory()->create();

        $this->expectException(InvalidRunTransition::class);

        app(TransitionRun::class)->handle($run, RunStatus::Completed);
    }

    public function test_the_owner_can_cancel_a_queued_run()
    {
        $featureRequest = FeatureRequest::factory()->create();
        $run = Run::factory()->for($featureRequest)->create();

        $this->actingAs($featureRequest->project->owner)
            ->post(route('runs.cancellation.store', $run))
            ->assertRedirect(route('feature-requests.show', $featureRequest));

        $this->assertSame(RunStatus::Cancelled, $run->refresh()->status);
        $this->assertSame(FeatureRequestStatus::Cancelled, $featureRequest->refresh()->status);
        $this->assertSame(
            [['from' => 'queued', 'to' => 'cancelling'], ['from' => 'cancelling', 'to' => 'cancelled']],
            $run->events()->pluck('data')->all(),
        );
    }

    public function test_cancelling_a_running_run_stops_it_at_its_next_tool_call()
    {
        $featureRequest = $this->invitationRequest();
        $this->useDriver(function (Run $run, ToolSession $tools) {
            $tools->call('look', 'list_files');
            app(CancelRun::class)->handle($run);
            $tools->call('change', 'write_file', ['path' => 'app/New.php', 'contents' => '<?php', 'expected_sha256' => null], $tools->revision());

            return new BuiltChange('Never reached.', []);
        });

        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertSame(['look'], $run->operations()->pluck('operation_key')->all());
        $this->assertSame(FeatureRequestStatus::Cancelled, $featureRequest->refresh()->status);
        $this->assertSame(WorkspaceStatus::Destroyed, $run->workspace->status);
        $this->assertSame(0, $run->verifications()->count());
    }

    public function test_other_users_cannot_cancel_a_run()
    {
        $run = Run::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('runs.cancellation.store', $run))
            ->assertForbidden();

        $this->assertSame(RunStatus::Queued, $run->refresh()->status);
    }

    public function test_an_exhausted_budget_stops_the_run_for_the_owners_decision()
    {
        config(['builder.construction.budgets.operations' => 1]);
        $featureRequest = $this->invitationRequest();

        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('The run used all 1 of its tool operations.', $run->error);
        $this->assertSame(['revise_request', 'use_stronger_model', 'involve_a_person'], $run->events()->get()->last()->data['choices']);
        $this->assertSame(FeatureRequestStatus::Generating, $featureRequest->refresh()->status);
    }

    public function test_the_change_is_read_back_from_the_workspace_not_taken_from_the_driver()
    {
        $featureRequest = $this->invitationRequest();
        $this->useDriver(function (Run $run, ToolSession $tools) {
            $tools->call('write', 'write_file', ['path' => 'app/Invitation.php', 'contents' => "<?php\n", 'expected_sha256' => null], $tools->revision());

            return new BuiltChange('Claims to add invitations everywhere.', []);
        });

        app(StartRun::class)->handle($featureRequest);

        $patch = (string) $featureRequest->refresh()->patch;
        $this->assertStringContainsString('diff --git a/app/Invitation.php b/app/Invitation.php', $patch);
        $this->assertStringNotContainsString('config/teams.php', $patch);
    }

    public function test_a_run_that_changes_nothing_asks_the_owner()
    {
        $featureRequest = $this->invitationRequest();
        $this->useDriver(fn () => new BuiltChange('Done!', []));

        $run = app(StartRun::class)->handle($featureRequest)->refresh();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame('The run finished without changing the project.', $run->error);
    }

    public function test_a_passing_verification_completes_the_run_after_review()
    {
        [$run, $verification] = $this->verifyingRun(VerificationStatus::Passed);

        app(CompleteRunVerification::class)->handle($verification);

        $run->refresh();
        $this->assertSame(RunStatus::Completed, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(['status', 'review', 'status'], $run->events()->pluck('type')->all());
        $this->assertTrue($run->events()->where('type', 'review')->sole()->data['approved']);
    }

    public function test_a_failing_verification_stops_the_run_for_the_owners_decision()
    {
        [$run, $verification] = $this->verifyingRun(VerificationStatus::Failed);

        app(CompleteRunVerification::class)->handle($verification);

        $this->assertSame(RunStatus::NeedsUserDecision, $run->refresh()->status);
        $this->assertSame('verification_failed', $run->events()->get()->last()->data['reason']);
    }

    public function test_the_reconciler_settles_a_finished_verification_whose_result_was_not_carried_back()
    {
        [$run] = $this->verifyingRun(VerificationStatus::Unverified);

        $this->artisan('runs:reconcile')->assertSuccessful();

        $this->assertSame(RunStatus::Completed, $run->refresh()->status);
    }

    public function test_the_page_shows_the_run_and_its_log()
    {
        $featureRequest = $this->invitationRequest();
        app(StartRun::class)->handle($featureRequest);

        $this->actingAs($featureRequest->project->owner)
            ->get(route('feature-requests.show', $featureRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.status', 'verifying')
                ->where('run.operations', 2)
                ->where('run.budget.operations', 30)
                ->where('run.events.0.type', 'created')
                ->where('run.events.6.data.tool', 'apply_patch'));
    }

    /**
     * Create a request the reference generator answers, for a project whose
     * source the reference patch applies to.
     */
    protected function invitationRequest(): FeatureRequest
    {
        $solutions = $this->useReferenceSolutions();
        $project = Project::factory()->create(['source_path' => "{$solutions}/source"]);

        return FeatureRequest::factory()->for($project)->create(['prompt' => 'Let owners invite people.']);
    }

    /**
     * Build runs with a driver made from the given callback.
     *
     * @param  Closure(Run, ToolSession): BuiltChange  $build
     */
    protected function useDriver(Closure $build): void
    {
        $driver = new class($build) implements ConstructionDriver
        {
            public function __construct(protected Closure $build) {}

            public function build(Run $run, ToolSession $tools): BuiltChange
            {
                return ($this->build)($run, $tools);
            }
        };

        app(ConstructionDriverManager::class)->extend('test', fn () => $driver);
        config(['builder.construction.driver' => 'test']);
    }

    /**
     * Create a run waiting on a finished verification.
     *
     * @return array{0: Run, 1: Verification}
     */
    protected function verifyingRun(VerificationStatus $status): array
    {
        $run = Run::factory()->for(FeatureRequest::factory()->generated())->create(['status' => RunStatus::Verifying]);
        $verification = $run->featureRequest->verifications()->create(['run_id' => $run->id, 'status' => $status, 'finished_at' => now()]);

        return [$run, $verification];
    }
}
