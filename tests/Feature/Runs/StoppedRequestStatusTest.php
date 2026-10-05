<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\FailRun;
use App\Actions\Runs\StartRun;
use App\Actions\Runs\TransitionRun;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * A request with no change made yet keeps its status with its run: when the
 * run stops, the request is no longer being made.
 */
class StoppedRequestStatusTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    public function test_a_first_version_stopped_by_a_busy_ai_service_is_no_longer_being_made()
    {
        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();
        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);
        FeaturePlanner::fake(fn () => throw RateLimitedException::forProvider('anthropic', 429));
        $request = FeatureRequest::factory()->for(Project::factory()->create(['source_path' => $this->makeProjectSource()]))->create(['status' => FeatureRequestStatus::Generating]);

        $run = app(StartRun::class)->handle($request)->refresh();

        $this->assertSame([RunStatus::NeedsUserDecision, StopReason::ProvidersUnavailable], [$run->status, $run->stop_reason]);
        $this->assertSame(FeatureRequestStatus::Failed, $request->refresh()->status);
        $this->assertSame($run->error, $request->error);
    }

    public function test_a_stop_while_setting_up_fails_the_request()
    {
        [$request, $run] = $this->making(RunStatus::Planning);

        app(FailRun::class)->handle($run, 'The workspace could not be prepared.', StopReason::ConstructionFailed);

        $this->assertSame(FeatureRequestStatus::Failed, $request->refresh()->status);
        $this->assertSame('The workspace could not be prepared.', $request->error);
    }

    public function test_a_change_out_of_attempts_stops_and_is_made_again_when_kept_trying()
    {
        [$request, $run] = $this->making(RunStatus::Implementing);

        app(TransitionRun::class)->handle($run, RunStatus::NeedsUserDecision, null, ['error' => 'The run used all of its attempts.'], ['reason' => StopReason::BudgetExhausted]);
        $this->assertSame(FeatureRequestStatus::Failed, $request->refresh()->status);

        // Going on from the work so far makes the change again.
        app(TransitionRun::class)->handle($run, RunStatus::Implementing);
        $this->assertSame(FeatureRequestStatus::Generating, $request->refresh()->status);
        $this->assertNull($request->error);
    }

    public function test_a_stop_that_waits_for_an_answer_or_comes_after_the_change_was_made_leaves_the_request()
    {
        [$asked, $run] = $this->making(RunStatus::Planning);
        app(TransitionRun::class)->handle($run, RunStatus::NeedsUserDecision, null, ['question' => ['text' => 'Which one?']], ['reason' => StopReason::Question]);
        $this->assertSame(FeatureRequestStatus::Generating, $asked->refresh()->status);

        [$made, $run] = $this->making(RunStatus::Verifying, FeatureRequestStatus::Generated);
        app(TransitionRun::class)->handle($run, RunStatus::NeedsUserDecision, null, ['error' => 'Verification did not pass.'], ['reason' => StopReason::VerificationFailed]);
        $this->assertSame(FeatureRequestStatus::Generated, $made->refresh()->status);

        // A cancelled run's request is cancelled where it is asked, not failed.
        [$cancelled, $run] = $this->making(RunStatus::Cancelling);
        app(TransitionRun::class)->handle($run, RunStatus::Cancelled);
        $this->assertSame(FeatureRequestStatus::Generating, $cancelled->refresh()->status);
    }

    /**
     * @return array{FeatureRequest, Run}
     */
    protected function making(RunStatus $status, FeatureRequestStatus $request = FeatureRequestStatus::Generating): array
    {
        $featureRequest = FeatureRequest::factory()->create(['status' => $request]);

        return [$featureRequest, Run::factory()->for($featureRequest)->create(['status' => $status])];
    }
}
