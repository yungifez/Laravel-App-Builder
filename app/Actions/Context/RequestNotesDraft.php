<?php

namespace App\Actions\Context;

use App\Actions\Operations\SummarizeSpend;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\NotesDraftStatus;
use App\Jobs\DraftProjectNotes;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

class RequestNotesDraft
{
    public function __construct(private ProjectRepository $repository, private ProjectNotes $notes, private SummarizeSpend $summarizeSpend) {}

    /**
     * Explore an app that has no notes and draft them, for the owner to
     * confirm. An app that already describes itself, or is being explored
     * now, is left alone.
     *
     * @throws ValidationException when today's AI spend reached its limit.
     */
    public function handle(Project $project): bool
    {
        if (! $this->repository->exists($project) || $project->notes_draft_status === NotesDraftStatus::Drafting || isset($this->notes->files($project)[ProjectContext::PROJECT_FILE])) {
            return false;
        }

        if ($this->summarizeSpend->dailyLimitReached()) {
            throw ValidationException::withMessages(['explore' => __('This is our fault: we paused new work for today to keep our costs in check. Nothing in your app changed. Try again tomorrow.')]);
        }

        $project->update(['notes_draft_status' => NotesDraftStatus::Drafting, 'notes_draft' => null, 'notes_draft_error' => null]);

        DraftProjectNotes::dispatch($project)->afterCommit();

        return true;
    }
}
