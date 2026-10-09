<?php

namespace App\Actions\Context;

use App\Actions\Billing\MeasureUsage;
use App\Actions\Operations\SummarizeSpend;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\NotesDraftStatus;
use App\Features\SpendPause;
use App\Jobs\DraftProjectNotes;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;

class RequestNotesDraft
{
    public function __construct(private ProjectRepository $repository, private ProjectNotes $notes, private SummarizeSpend $summarizeSpend, private MeasureUsage $measureUsage) {}

    /**
     * Explore an app that has no notes and draft them, for the owner to
     * confirm. An app that already describes itself, or is being explored
     * now, is left alone.
     *
     * @throws ValidationException when today's AI spend, or the owner's plan
     *                             for this month, is used up.
     */
    public function handle(Project $project): bool
    {
        if (! $this->repository->exists($project) || $project->notes_draft_status === NotesDraftStatus::Drafting || isset($this->notes->files($project)[ProjectContext::PROJECT_FILE])) {
            return false;
        }

        if ($this->summarizeSpend->dailyLimitReached()) {
            throw ValidationException::withMessages(['explore' => SpendPause::message()]);
        }

        // Exploring is AI use like any change, so it counts against the plan.
        $usage = $this->measureUsage->handle($project->owner);

        if ($usage['reached']) {
            throw ValidationException::withMessages(['explore' => __('You have used all the AI use your plan includes this month. It starts again on :date, or you can move to a bigger plan in Settings. Nothing in your app changed.', [
                'date' => $usage['resets_at']->isoFormat('D MMMM'),
            ])]);
        }

        $project->update(['notes_draft_status' => NotesDraftStatus::Drafting, 'notes_draft' => null, 'notes_draft_error' => null]);

        DraftProjectNotes::dispatch($project)->afterCommit();

        return true;
    }
}
