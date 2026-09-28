<?php

namespace App\Actions\VisualEditing;

use App\Models\User;
use App\Models\VisualEdit;
use Illuminate\Validation\ValidationException;

class RevertVisualEdit
{
    public function __construct(
        private SwapElementClasses $swapElementClasses,
        private SwapMovedElement $swapMovedElement,
    ) {}

    /**
     * Undo a change to how an element looks with a new commit, only while
     * the element still looks the way the change left it. The commit
     * rebuilds the editable preview, so the owner sees it undone.
     *
     * @throws ValidationException when the edit was already undone or the element changed since.
     */
    public function handle(VisualEdit $edit, User $owner): VisualEdit
    {
        if ($edit->reverted_at !== null) {
            throw ValidationException::withMessages(['edit' => __('This change was already undone.')]);
        }

        // A move, new words, a new link address, a new picture, a copy or a
        // removal put back the whole file; a new look puts back only the
        // element's classes.
        $sha = $edit->rewritesFile()
            ? $this->swapMovedElement->handle(
                $edit,
                $edit->commit_sha,
                $edit->base_revision,
                match ($edit->kind()) {
                    'move' => "Undo moving <{$edit->tag}>",
                    'link' => "Undo where <{$edit->tag}> goes",
                    'picture' => "Undo the new picture in <{$edit->tag}>",
                    'duplicate' => "Undo copying <{$edit->tag}>",
                    'remove' => "Undo removing <{$edit->tag}>",
                    default => "Undo new words in <{$edit->tag}>",
                }."\n\nThis undoes commit {$edit->commit_sha}.",
                $owner,
            )
            : $this->swapElementClasses->handle(
                $edit,
                $edit->classes_after,
                $edit->classes_before,
                "Undo a change to how <{$edit->tag}> looks\n\nThis undoes commit {$edit->commit_sha}.",
                $owner,
            );

        // The new commit rebuilds the editable preview (ProjectCommitted).
        $edit->update(['revert_sha' => $sha, 'reverted_at' => now()]);

        return $edit;
    }
}
