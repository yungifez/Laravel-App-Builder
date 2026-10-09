<?php

namespace App\Actions\Features;

use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Projects\ProjectRepository;

class AmendChangeFromDesign
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Read the change's code back from its design branch, so the owner's
     * design edits are part of what they keep. Only a change that still
     * waits is amended; once kept or set aside, its code is final.
     */
    public function handle(FeatureRequest $featureRequest): void
    {
        if ($featureRequest->status !== FeatureRequestStatus::Generated || $featureRequest->design_base === null) {
            return;
        }

        $project = $featureRequest->project;
        // Made the same way the change's own patch was, so with no edits it
        // reads back as the same text.
        $head = $this->repository->head($project, $featureRequest->designBranch());
        $patch = $this->repository->patch($project, $featureRequest->design_base, $head);

        if ($patch !== $featureRequest->patch) {
            $featureRequest->update(['patch' => $patch]);
        }
    }
}
