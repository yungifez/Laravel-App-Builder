<?php

namespace App\Actions\VisualEditing;

use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

class RevertVisualEdit
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Undo a change to how an element looks with a new commit. The commit
     * rebuilds the editable preview, so the owner sees it undone.
     *
     * @throws ValidationException when the edit was already undone or later changes build on it.
     */
    public function handle(VisualEdit $edit, User $owner): VisualEdit
    {
        if ($edit->reverted_at !== null) {
            throw ValidationException::withMessages(['edit' => __('This change was already undone.')]);
        }

        try {
            $sha = $this->repository->revert(
                $edit->project,
                $edit->commit_sha,
                "Undo a change to how <{$edit->tag}> looks\n\nThis reverts commit {$edit->commit_sha}.\nBuilder-Visual-Edit: yes",
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        // The new commit rebuilds the editable preview (ProjectCommitted).
        $edit->update(['revert_sha' => $sha, 'reverted_at' => now()]);

        return $edit;
    }
}
