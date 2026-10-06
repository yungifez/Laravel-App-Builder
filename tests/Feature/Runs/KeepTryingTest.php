<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\KeepTryingRun;
use App\Context\ChangeClassification;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\ExecuteRun;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeepTryingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class, VerifyFeatureRequest::class]);
        config(['builder.construction.budgets.repairs' => 1]);
    }

    public function test_a_change_out_of_tries_keeps_trying_from_its_work_so_far_when_the_owner_asks()
    {
        $run = $this->outOfTries();
        $request = $run->featureRequest;

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame(['reason' => 'verification_failed', 'details' => ["Tests failed:\nthe count is wrong."]], $run->feedback);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.can_keep_trying', true));

        $this->actingAs($request->user)
            ->post(route('feature-requests.keep-trying.store', $request))
            ->assertRedirect(route('projects.show', ['project' => $request->project, 'change' => $request->uuid]));

        $run->refresh();
        $this->assertSame(RunStatus::Implementing, $run->status);
        $this->assertSame(2, $run->repairs);
        $this->assertNull($run->error);
        // The next pass is told what to fix, and has as many tries again.
        $this->assertSame(["Tests failed:\nthe count is wrong."], $run->feedback['details']);
        $this->assertSame(2, $run->repairLimit());
        // Its time and tool operations count again from now.
        $this->assertTrue($run->budgetSince()?->isAfter(now()->subMinute()));
        Queue::assertPushed(ExecuteRun::class);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_keep_trying', false)
                ->where('run.work', fn ($work) => collect($work)->contains('text', 'You asked me to keep trying, so I went back to fix it')));
    }

    public function test_a_change_that_stopped_before_its_feedback_was_kept_works_it_out_from_the_review()
    {
        $run = $this->outOfTries();
        $run->update([
            'stop_reason' => 'review_findings',
            // From an earlier repair: it says nothing about why it stopped.
            'feedback' => ['reason' => 'verification_failed', 'details' => ['An old failure.']],
            'review' => ['approved' => false, 'summary' => 'Not yet.', 'preserved' => [], 'verified' => [], 'coverage' => [], 'findings' => [
                ['severity' => 'blocking', 'file' => 'app/Models/Booking.php', 'summary' => 'A full class still takes bookings.'],
                ['severity' => 'minor', 'file' => null, 'summary' => 'The label could be bolder.'],
            ], 'changes' => [], 'classification' => (new ChangeClassification)->toArray()],
        ]);

        $this->actingAs($run->featureRequest->user)
            ->post(route('feature-requests.keep-trying.store', $run->featureRequest))
            ->assertRedirect();

        $this->assertSame(['reason' => 'review_findings', 'details' => ['app/Models/Booking.php: A full class still takes bookings.']], $run->refresh()->feedback);
        $this->assertSame(RunStatus::Implementing, $run->status);
    }

    public function test_only_a_change_that_ran_out_of_tries_can_keep_trying()
    {
        $run = $this->outOfTries();
        $run->update(['stop_reason' => 'no_changes']);
        $request = $run->featureRequest;

        $this->actingAs($request->user)
            ->post(route('feature-requests.keep-trying.store', $request))
            ->assertSessionHasErrors('keep_trying');

        $this->assertSame(RunStatus::NeedsUserDecision, $run->refresh()->status);
    }

    public function test_a_change_whose_work_so_far_was_cleaned_up_can_only_start_over()
    {
        $run = $this->outOfTries();
        $run->workspace->update(['status' => WorkspaceStatus::Destroyed]);

        $this->assertFalse(KeepTryingRun::possible($run->featureRequest->refresh()));
    }

    public function test_only_people_who_may_ask_for_changes_can_keep_one_trying()
    {
        $run = $this->outOfTries();

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.keep-trying.store', $run->featureRequest))
            ->assertForbidden();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->refresh()->status);
    }

    public function test_a_stopped_change_offers_to_go_on_and_keeps_its_plan_answers_and_choices()
    {
        $stopped = $this->stopped(['patch' => "diff --git a/app/A.php b/app/A.php\n"]);
        $request = $stopped->featureRequest;

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.can_keep_trying', true)->where('featureRequest.can_go_on', true));

        $this->actingAs($request->user)
            ->post(route('feature-requests.keep-trying.store', $request))
            ->assertRedirect(route('projects.show', ['project' => $request->project, 'change' => $request->uuid]));

        $run = $request->refresh()->latestRun;
        $this->assertNotSame($stopped->id, $run->id);
        $this->assertSame(RunStatus::Implementing, $run->status);
        $this->assertSame($stopped->plan, $run->plan);
        $this->assertSame(['Members pay at the door.'], $run->kept_assumptions);
        $this->assertSame($stopped->answers, $run->answers);
        $this->assertSame('resumed', $run->feedback['reason']);
        $this->assertSame(FeatureRequestStatus::Generating, $request->status);
        $this->assertSame(0, FeatureRequest::query()->where('retry_of_id', $request->id)->count());
        Queue::assertPushed(ExecuteRun::class, fn (ExecuteRun $job) => $job->run->is($run));
    }

    public function test_a_change_that_failed_on_our_side_goes_on_too()
    {
        $stopped = $this->stopped();
        $stopped->update(['status' => RunStatus::Failed, 'stop_reason' => null]);

        $this->assertTrue(KeepTryingRun::possible($stopped->featureRequest->refresh()));

        $run = app(KeepTryingRun::class)->handle($stopped->featureRequest);

        // Nothing was made yet, so there is nothing to say about it.
        $this->assertNull($run->feedback);
        $this->assertSame(RunStatus::Implementing, $run->status);
    }

    public function test_a_stopped_change_cannot_go_on_once_kept_started_over_or_without_a_plan_to_build()
    {
        $kept = $this->stopped();
        $kept->featureRequest->update(['commit_sha' => 'abc']);
        $this->assertFalse(KeepTryingRun::possible($kept->featureRequest->refresh()));

        $startedOver = $this->stopped();
        FeatureRequest::factory()->for($startedOver->featureRequest->project)->create(['retry_of_id' => $startedOver->feature_request_id]);
        $this->assertFalse(KeepTryingRun::possible($startedOver->featureRequest->refresh()));

        $unplanned = $this->stopped();
        $unplanned->update(['plan' => null]);
        $this->assertFalse(KeepTryingRun::possible($unplanned->featureRequest->refresh()));

        // A limit would stop it the same way: the owner's plan comes first.
        $limited = $this->stopped();
        $limited->update(['status' => RunStatus::Failed, 'stop_reason' => 'usage_limit']);
        config(['billing.plans.free.monthly_usd' => 5]);
        $limited->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 6]);
        $this->assertFalse(KeepTryingRun::possible($limited->featureRequest->refresh()));

        // Its plan only answered a question: there is nothing to build.
        $answered = $this->stopped();
        $answered->update(['plan' => [...$answered->plan, 'answer' => 'Yes, they can.']]);
        $this->assertFalse(KeepTryingRun::possible($answered->featureRequest->refresh()));

        $this->actingAs($answered->featureRequest->user)
            ->post(route('feature-requests.keep-trying.store', $answered->featureRequest))
            ->assertSessionHasErrors('keep_trying');
        $this->assertSame(1, $answered->featureRequest->runs()->count());
    }

    /**
     * A planned change the owner stopped while it was being built.
     *
     * @param  array<string, mixed>  $request
     */
    protected function stopped(array $request = []): Run
    {
        $featureRequest = FeatureRequest::factory()->create(['status' => FeatureRequestStatus::Cancelled, ...$request]);

        return Run::factory()->for($featureRequest)->create([
            'status' => RunStatus::Cancelled,
            'stop_reason' => 'cancelled',
            'plan' => ['summary' => 'Each class shows how many places are left.', 'acceptance_criteria' => ['Each class shows its places left.'], 'cases' => [], 'written_tests' => [], 'written_files' => [], 'assumptions' => [], 'tasks' => [], 'steps' => [], 'acceptance' => [], 'solution_key' => null],
            'answers' => [['question' => 'Who pays?', 'answer' => 'Members, at the door.', 'decided_by' => 'owner']],
            'kept_assumptions' => ['Members pay at the door.'],
        ]);
    }

    /**
     * A change whose checks failed again after its one repair.
     */
    protected function outOfTries(): Run
    {
        $request = FeatureRequest::factory()->generated()->create();
        $run = Run::factory()->for($request)->create([
            'status' => RunStatus::Verifying,
            'driver' => 'worker',
            'repairs' => 1,
            'started_at' => now()->subHour(),
            'plan' => ['summary' => 'Each class shows how many places are left.', 'acceptance_criteria' => ['Each class shows its places left.'], 'cases' => [['criterion' => 1, 'kind' => 'base', 'says' => 'A class with two of ten places taken shows eight left.', 'none' => null], ['criterion' => 1, 'kind' => 'alternate', 'says' => 'A full class shows that it is full.', 'none' => null], ['criterion' => 1, 'kind' => 'exception', 'says' => 'A class that does not exist is not found.', 'none' => null]], 'written_tests' => [], 'written_files' => [], 'assumptions' => [], 'tasks' => [], 'steps' => [], 'acceptance' => [], 'solution_key' => null],
            'workspace_id' => Workspace::factory()->create()->id,
        ]);

        /** @var Verification $verification */
        $verification = $request->verifications()->create(['run_id' => $run->id, 'status' => VerificationStatus::Queued]);
        $verification->update(['status' => VerificationStatus::Failed, 'finished_at' => now(), 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'the count is wrong.'],
        ]]);
        app(CompleteRunVerification::class)->handle($verification);

        return $run->refresh();
    }
}
