<?php

namespace App\Actions\Features;

use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

class RequestStepChange
{
    public function __construct(
        private StartRun $startRun,
        private ProjectRepository $repository,
    ) {}

    /**
     * Ask for a change to one step of a generated feature, as a follow-up
     * request. It builds on top of the parent's change, or on the project's
     * latest commit once the parent is accepted.
     *
     * @throws ValidationException when the parent was not generated, was undone or has no such step.
     */
    public function handle(FeatureRequest $parent, User $requester, string $stepKey, string $prompt): FeatureRequest
    {
        if ($parent->status !== FeatureRequestStatus::Generated || $parent->reverted_at !== null || $parent->step($stepKey) === null) {
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
            'base_revision' => $parent->commit_sha !== null ? $this->repository->head($parent->project) : $parent->base_revision,
        ]);

        $this->startRun->handle($followUp);

        return $followUp;
    }
}
