<?php

namespace App\Actions\VisualEditing;

use App\Models\User;
use App\Models\VisualEdit;
use App\VisualEditing\ElementName;
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

        $name = ElementName::for($edit->tag);
        $picture = $edit->tag === 'img' ? 'the new picture' : "the new picture in {$name}";

        // A move, new words, a new link address, a new picture, a copy or a
        // removal put back the whole file; a new look puts back only the
        // element's classes.
        $sha = $edit->rewritesFile()
            ? $this->swapMovedElement->handle(
                $edit,
                (string) $edit->revert_sha,
                $edit->commit_sha,
                match ($edit->kind()) {
                    'move' => "Redo moving {$name}",
                    'link' => "Redo where {$name} goes",
                    'picture' => "Redo {$picture}",
                    'theme' => "Redo a change to the app's colours",
                    'duplicate' => "Redo copying {$name}",
                    'add' => "Redo adding {$name}",
                    'remove' => "Redo removing {$name}",
                    default => "Redo new words in {$name}",
                }."\n\nThis makes commit {$edit->commit_sha} again.",
                $owner,
            )
            : $this->swapElementClasses->handle(
                $edit,
                $edit->classes_before,
                $edit->classes_after,
                "Redo a change to how {$name} looks\n\nThis makes commit {$edit->commit_sha} again.",
                $owner,
            );

        // The new commit rebuilds the editable preview (ProjectCommitted).
        $edit->update(['commit_sha' => $sha, 'revert_sha' => null, 'reverted_at' => null]);

        return $edit;
    }
}
