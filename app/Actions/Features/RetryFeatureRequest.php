<?php

namespace App\Actions\Features;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Operations\SummarizeSpend;
use App\Enums\ExperimentStatus;
use App\Enums\FeatureRequestStatus;
use App\Enums\NextStep;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RetryFeatureRequest
{
    public function __construct(
        private RequestFeature $requestFeature,
        private RequestStepChange $requestStepChange,
        private RequestFollowUp $requestFollowUp,
    ) {}

    /**
     * Determine if the request stopped without a change, so asking again
     * is the owner's next step.
     */
    public static function retryable(FeatureRequest $featureRequest): bool
    {
        if (self::stillWorking($featureRequest)) {
            return false;
        }

        // A change that waits for the owner's answer has not stopped.
        if ($featureRequest->latestRun?->question !== null) {
            return false;
        }

        // Nor has one that stopped to ask about something: the owner's
        // next step is to answer, so trying again is not offered.
        if ($featureRequest->latestRun?->stop_reason?->nextStep() === NextStep::Answer) {
            return false;
        }

        // It was tried again already: that try stands for it now.
        if (FeatureRequest::query()->where('retry_of_id', $featureRequest->id)->exists()) {
            return false;
        }

        // The owner was told to try again tomorrow: today it would only
        // stop the same way.
        if ($featureRequest->latestRun?->stop_reason === StopReason::SpendLimit && app(SummarizeSpend::class)->dailyLimitReached()) {
            return false;
        }

        // Neither while the owner's plan has no use left this month.
        if ($featureRequest->latestRun?->stop_reason === StopReason::UsageLimit && app(MeasureUsage::class)->handle($featureRequest->project->owner)['reached']) {
            return false;
        }

        // A made change waits for the owner to try it, unless the run
        // stopped while checking it: then the owner can only ask again.
        if ($featureRequest->status === FeatureRequestStatus::Generated) {
            return self::stoppedWhileChecking($featureRequest);
        }

        return in_array($featureRequest->status, [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled], true)
            || in_array($featureRequest->latestRun?->status, [RunStatus::Failed, RunStatus::NeedsUserDecision, RunStatus::Cancelled], true);
    }

    /**
     * Determine if the change itself stopped its checks, or no longer fits
     * the app to be tried: checking or trying it again cannot help, so
     * only making it again does.
     */
    /**
     * Whether its run is still on it. Checks that fail send the change back
     * to be fixed, so a failed check alone is not a stop, and another try
     * would build a second change beside the one still being written.
     */
    protected static function stillWorking(FeatureRequest $featureRequest): bool
    {
        $status = $featureRequest->latestRun?->status;

        return $status !== null && ! $status->finished() && $status !== RunStatus::NeedsUserDecision;
    }

    public static function mustBeMadeAgain(FeatureRequest $featureRequest): bool
    {
        return $featureRequest->status === FeatureRequestStatus::Generated
            && $featureRequest->commit_sha === null
            && $featureRequest->reverted_at === null
            && ($featureRequest->verifications()->latest('id')->first()?->stopped_because?->retryable() === true
                || $featureRequest->previews()->latest('id')->first()?->no_longer_fits === true);
    }

    /**
     * Determine if a made change was never kept and its run stopped, or the
     * owner stopped it, before the checks and review were done. A change
     * whose own files stopped the checks before any check ran counts too,
     * as does one that no longer fits the app to be tried.
     */
    public static function stoppedWhileChecking(FeatureRequest $featureRequest): bool
    {
        if (self::stillWorking($featureRequest)) {
            return false;
        }

        // A proposal from the checks waits for the owner's answer, just as a
        // question does: nothing stopped.
        if ($featureRequest->status !== FeatureRequestStatus::Generated
            || $featureRequest->commit_sha !== null
            || $featureRequest->reverted_at !== null
            || self::waitsOnTheOwner($featureRequest)) {
            return false;
        }

        if (self::mustBeMadeAgain($featureRequest)) {
            return true;
        }

        return in_array($featureRequest->latestRun?->status, [RunStatus::Failed, RunStatus::NeedsUserDecision, RunStatus::Cancelled], true);
    }

    /**
     * Determine if the change waits on the owner's answer: to a question,
     * or to something the checks found that is not answered yet. Once it is
     * answered, the run's own stop stands again.
     */
    public static function waitsOnTheOwner(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        return $run?->question !== null
            || ($run?->stop_reason === StopReason::FindingProposed && app(ProposeFindings::class)->pending($featureRequest) !== []);
    }

    /**
     * Ask for the same change again, as a new request on the app as it is
     * now. The stopped request stays in the history as it was.
     *
     * @throws ValidationException when the request did not stop.
     */
    public function handle(FeatureRequest $featureRequest, User $requester): FeatureRequest
    {
        if (! self::retryable($featureRequest)) {
            throw ValidationException::withMessages(['retry' => __('This change did not stop, so there is nothing to try again.')]);
        }

        return $this->rebuild($featureRequest, $requester);
    }

    /**
     * Ask for the same change again on the app as it is now, whatever state
     * the request is in. The asks it builds on that are not kept yet are
     * asked again with it, in the order they were asked, as one request.
     *
     * @param  list<FeatureRequest>  $before
     */
    public function rebuild(FeatureRequest $featureRequest, User $requester, array $before = []): FeatureRequest
    {
        $asks = [...$before, $featureRequest];
        $first = $asks[0];
        $parent = $first->parent;
        $prompt = implode("\n\n", array_map(fn (FeatureRequest $ask) => $ask->prompt, $asks));
        $images = array_merge(...array_map(fn (FeatureRequest $ask) => $ask->images ?? [], $asks));

        $retry = match (true) {
            $before === [] && $parent !== null && $featureRequest->target_step !== null => $this->requestStepChange->handle($parent, $requester, $featureRequest->target_step, $featureRequest->prompt),
            // A message in a chat is tried again in that chat while there is
            // still something there to build on.
            $parent !== null && RequestFollowUp::continuable($parent) => $this->requestFollowUp->handle($parent, $requester, $prompt, selection: $featureRequest->selection, images: $images),
            // Tried again where it was asked: in its idea while that is
            // open, otherwise in the main app.
            default => $this->requestFeature->handle($first->project, $requester, $prompt, $featureRequest->selection, $first->experiment?->status === ExperimentStatus::Open ? $first->experiment : null, images: $images),
        };

        $retry->update(['retry_of_id' => $featureRequest->id]);

        return $retry;
    }
}
