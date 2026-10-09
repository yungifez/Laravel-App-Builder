<?php

namespace App\Actions\Features;

use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RequestStepChange
{
    public function __construct(private RequestFollowUp $requestFollowUp) {}

    /**
     * Ask for a change to one step of a generated feature, as a follow-up
     * request. It builds on top of the parent's change, or on the project's
     * latest commit once the parent is accepted.
     *
     * @throws ValidationException when the parent was not generated, was undone or has no such step.
     */
    public function handle(FeatureRequest $parent, User $requester, string $stepKey, string $prompt): FeatureRequest
    {
        if (! RequestFollowUp::continuable($parent) || $parent->step($stepKey) === null) {
            throw ValidationException::withMessages([
                'step' => __('Select a step of a generated change.'),
            ]);
        }

        return $this->requestFollowUp->handle($parent, $requester, $prompt, $stepKey);
    }
}
