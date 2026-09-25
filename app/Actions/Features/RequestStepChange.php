<?php

namespace App\Actions\Features;

use App\Enums\FeatureRequestStatus;
use App\Jobs\GenerateFeature;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RequestStepChange
{
    /**
     * Ask for a change to one step of a generated feature, as a follow-up request.
     *
     * @throws ValidationException when the parent was not generated or has no such step.
     */
    public function handle(FeatureRequest $parent, User $requester, string $stepKey, string $prompt): FeatureRequest
    {
        if ($parent->status !== FeatureRequestStatus::Generated || $parent->step($stepKey) === null) {
            throw ValidationException::withMessages([
                'step' => __('Select a step of a generated change.'),
            ]);
        }

        $followUp = $parent->followUps()->create([
            'user_id' => $requester->id,
            'project_id' => $parent->project_id,
            'prompt' => $prompt,
            'target_step' => $stepKey,
            'status' => FeatureRequestStatus::Generating,
            'generator' => $parent->generator,
        ]);

        GenerateFeature::dispatch($followUp);

        return $followUp;
    }
}
