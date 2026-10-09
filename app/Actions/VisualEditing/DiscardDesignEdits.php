<?php

namespace App\Actions\VisualEditing;

use App\Models\Project;
use App\Projects\ProjectRepository;
use App\VisualEditing\DesignDrafts;

class DiscardDesignEdits
{
    public function __construct(
        private DesignDrafts $designDrafts,
        private ProjectRepository $repository,
    ) {}

    /**
     * Throw away the design edits that wait on the app. The app never had
     * them, so its preview simply shows the app again.
     */
    public function handle(Project $project): void
    {
        $draft = $this->designDrafts->find($project);

        if ($draft === null) {
            return;
        }

        $draft->update(['dismissed_at' => now()]);

        if ($this->repository->hasBranch($project, $draft->designBranch())) {
            $this->repository->deleteBranch($project, $draft->designBranch(), $project->branch());
        }

        $this->designDrafts->rebuildAppPreview($project);
    }
}
