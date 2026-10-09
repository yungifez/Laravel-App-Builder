<?php

namespace App\Actions\Features;

use App\Models\FeatureRequest;
use App\Projects\ProjectRepository;

class OpenChangeForDesign
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Put a waiting change on a branch of its own, so design edits on its
     * copy have commits to land on, and return the branch's newest commit.
     * The branch holds the change's base, the changes it follows up on and
     * then the change itself; design_base marks where the change's own code
     * starts, so its patch can be read back with the edits in it.
     */
    public function handle(FeatureRequest $featureRequest): string
    {
        $project = $featureRequest->project;
        $branch = $featureRequest->designBranch();

        if ($this->repository->hasBranch($project, $branch)) {
            if ($featureRequest->design_base !== null) {
                return $this->repository->head($project, $branch);
            }

            // An earlier try stopped half way; start it again.
            $this->repository->deleteBranch($project, $branch, $project->branch());
        }

        $this->repository->createBranch($project, $branch, (string) $featureRequest->base_revision);

        $base = (string) $featureRequest->base_revision;
        $earlier = array_values(array_filter(
            array_map(fn (FeatureRequest $request) => (string) $request->patch, array_slice($featureRequest->lineage(), 0, -1)),
            fn (string $patch) => trim($patch) !== '',
        ));

        if ($earlier !== []) {
            $base = $this->repository->commitPatches($project, $base, $earlier, 'Apply the changes this one follows up on', null, $branch);
        }

        $featureRequest->update(['design_base' => $base]);

        if (trim((string) $featureRequest->patch) === '') {
            return $base;
        }

        return $this->repository->commitPatches($project, $base, [(string) $featureRequest->patch], 'Apply the change', null, $branch);
    }
}
