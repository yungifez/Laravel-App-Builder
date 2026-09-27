<?php

namespace App\Actions\Features;

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
        if ($featureRequest->status === FeatureRequestStatus::Generated || $featureRequest->latestRun?->question !== null) {
            return false;
        }

        return in_array($featureRequest->status, [FeatureRequestStatus::Failed, FeatureRequestStatus::Cancelled], true)
            || in_array($featureRequest->latestRun?->status, [RunStatus::Failed, RunStatus::NeedsUserDecision, RunStatus::Cancelled], true);
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
     * the request is in.
     */
    public function rebuild(FeatureRequest $featureRequest, User $requester): FeatureRequest
    {
        $parent = $featureRequest->parent;

        $retry = match (true) {
            $parent !== null && $featureRequest->target_step !== null => $this->requestStepChange->handle($parent, $requester, $featureRequest->target_step, $featureRequest->prompt),
            // A message in a chat is tried again in that chat while there is
            // still something there to build on.
            $parent !== null && RequestFollowUp::continuable($parent) => $this->requestFollowUp->handle($parent, $requester, $featureRequest->prompt, selection: $featureRequest->selection, images: $featureRequest->images ?? []),
            // Tried again where it was asked: in its idea while that is
            // open, otherwise in the main app.
            default => $this->requestFeature->handle($featureRequest->project, $requester, $featureRequest->prompt, $featureRequest->selection, $featureRequest->experiment?->status === ExperimentStatus::Open ? $featureRequest->experiment : null, images: $featureRequest->images ?? []),
        };

        $retry->update(['retry_of_id' => $featureRequest->id]);

        return $retry;
    }
}
