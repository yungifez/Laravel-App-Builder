<?php

namespace App\Actions\Context;

use App\Context\ProjectContext;
use App\Enums\NotesDraftStatus;
use App\Jobs\DraftProjectNotes;
use App\Models\Project;
use App\Projects\ProjectRepository;

class RequestNotesDraft
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Draft notes for an imported app that has none, for the owner to
     * confirm. An app that already describes itself is left alone.
     */
    public function handle(Project $project): bool
    {
        if (! $this->repository->exists($project) || $this->repository->show($project, $this->repository->head($project), ProjectContext::PROJECT_FILE) !== null) {
            return false;
        }

        $project->update(['notes_draft_status' => NotesDraftStatus::Drafting, 'notes_draft' => null, 'notes_draft_error' => null]);

        DraftProjectNotes::dispatch($project)->afterCommit();

        return true;
    }
}
