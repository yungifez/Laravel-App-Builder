<?php

namespace App\Actions\VisualEditing;

use App\Actions\Runs\CompleteRunVerification;
use App\Enums\VerificationStatus;
use App\Models\Verification;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\DesignDrafts;

class CommitDesignEdits
{
    public function __construct(
        private ProjectRepository $repository,
        private CompleteRunVerification $completeRunVerification,
    ) {}

    /**
     * Determine whether the checks let the edits in: every check passed,
     * or one failed only the way it failed before the edits.
     */
    public function cleared(Verification $verification): bool
    {
        return match ($verification->status) {
            VerificationStatus::Passed, VerificationStatus::Unverified => true,
            VerificationStatus::Failed => $this->completeRunVerification->failures($verification) === [],
            default => false,
        };
    }

    /**
     * Bring checked design edits into the app as one commit. Only edits
     * the checks cleared join it, and only onto the app they were checked
     * on: when the app changed while they were checked, they wait, and
     * keeping them again checks them on the app as it is now.
     */
    public function handle(Verification $verification): void
    {
        $draft = $verification->featureRequest;

        if (! DesignDrafts::isDraft($draft)
            || $draft->accepted_at !== null
            || $draft->dismissed_at !== null
            || ! $this->cleared($verification)) {
            return;
        }

        $project = $draft->project;

        if ($this->repository->head($project) !== $draft->base_revision) {
            return;
        }

        // Marked first, so the new commit does not move the draft onto
        // itself (RebuildEditablePreview).
        $draft->update(['accepted_at' => now()]);

        try {
            $sha = $this->repository->merge($project, $draft->designBranch(), $project->branch(), 'Change the design', ['name' => $draft->user->name, 'email' => $draft->user->email]);
        } catch (RepositoryConflict) {
            $draft->update(['accepted_at' => null]);

            return;
        }

        $draft->update(['commit_sha' => $sha]);
        $this->repository->deleteBranch($project, $draft->designBranch(), $project->branch());
    }
}
