<?php

namespace App\Actions\Features;

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
    ) {}

    /**
     * Determine if the request stopped without a change, so asking again
     * is the owner's next step.
     */
    public static function retryable(FeatureRequest $featureRequest): bool
    {
        if ($featureRequest->status === FeatureRequestStatus::Generated) {
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

        $parent = $featureRequest->parent;

        if ($parent !== null && $featureRequest->target_step !== null) {
            return $this->requestStepChange->handle($parent, $requester, $featureRequest->target_step, $featureRequest->prompt);
        }

        return $this->requestFeature->handle($featureRequest->project, $requester, $featureRequest->prompt, $featureRequest->selection);
    }
}
