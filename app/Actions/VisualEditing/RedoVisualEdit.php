<?php

namespace App\Actions\VisualEditing;

use App\Models\User;
use App\Models\VisualEdit;
use Illuminate\Validation\ValidationException;

class RedoVisualEdit
{
    public function __construct(
        private SwapElementClasses $swapElementClasses,
        private SwapMovedElement $swapMovedElement,
    ) {}

    /**
     * Make an undone change to how an element looks again, only while the
     * element still looks the way the undo left it.
     *
     * @throws ValidationException when the edit is not undone or the element changed since.
     */
    public function handle(VisualEdit $edit, User $owner): VisualEdit
    {
        if ($edit->reverted_at === null) {
            throw ValidationException::withMessages(['edit' => __('This change is already in place.')]);
        }

        $sha = $edit->moves()
            ? $this->swapMovedElement->handle(
                $edit,
                (string) $edit->revert_sha,
                $edit->commit_sha,
                "Redo moving <{$edit->tag}>\n\nThis makes commit {$edit->commit_sha} again.",
                $owner,
            )
            : $this->swapElementClasses->handle(
                $edit,
                $edit->classes_before,
                $edit->classes_after,
                "Redo a change to how <{$edit->tag}> looks\n\nThis makes commit {$edit->commit_sha} again.",
                $owner,
            );

        // The new commit rebuilds the editable preview (ProjectCommitted).
        $edit->update(['commit_sha' => $sha, 'revert_sha' => null, 'reverted_at' => null]);

        return $edit;
    }
}
