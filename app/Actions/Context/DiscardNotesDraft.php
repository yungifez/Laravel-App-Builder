<?php

namespace App\Actions\Context;

use App\Models\Project;

class DiscardNotesDraft
{
    /**
     * Forget the drafted notes, or a failed attempt to draft them. The app
     * is not changed.
     */
    public function handle(Project $project): void
    {
        $project->update(['notes_draft_status' => null, 'notes_draft' => null, 'notes_draft_error' => null]);
    }
}
