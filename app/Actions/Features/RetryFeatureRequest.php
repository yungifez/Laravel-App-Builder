<?php

namespace App\Actions\Features;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Operations\SummarizeSpend;
use App\Enums\ExperimentStatus;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
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
        // A change that waits for the owner's answer has not stopped.
        if ($featureRequest->latestRun?->question !== null) {
            return false;
        }

        // It was tried again already: that try stands for it now.
        if (FeatureRequest::query()->where('retry_of_id', $featureRequest->id)->exists()) {
            return false;
        }

        // The owner was told to try again tomorrow: today it would only
        // stop the same way.
        if ($featureRequest->latestRun?->stop_reason === 'spend_limit' && app(SummarizeSpend::class)->dailyLimitReached()) {
            return false;
        }

        // Neither while the owner's plan has no use left this month.
        if ($featureRequest->latestRun?->stop_reason === 'usage_limit' && app(MeasureUsage::class)->handle($featureRequest->project->owner)['reached']) {
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
        if ($featureRequest->status !== FeatureRequestStatus::Generated
            || $featureRequest->commit_sha !== null
            || $featureRequest->reverted_at !== null
            || $featureRequest->latestRun?->question !== null) {
            return false;
        }

        if (self::mustBeMadeAgain($featureRequest)) {
            return true;
        }

        return in_array($featureRequest->latestRun?->status, [RunStatus::Failed, RunStatus::NeedsUserDecision, RunStatus::Cancelled], true);
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
