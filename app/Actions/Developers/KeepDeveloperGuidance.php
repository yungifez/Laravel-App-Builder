<?php

namespace App\Actions\Developers;

use App\Actions\Context\UpdateProjectNotes;
use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Models\DeveloperReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner keeps the guidance a developer gave. It joins "Engineering
 * direction" in the app's notes, which every later change and its second
 * look follow (architecture §29.3). The developer proposes; only the owner
 * makes it part of the app. Each point says who gave it and when, so
 * guidance is never mistaken for the owner's own decision or for ours, and
 * the review keeps which code it was given on.
 */
class KeepDeveloperGuidance
{
    public function __construct(private ProjectNotes $notes) {}

    /**
     * Add the chosen points of the developer's guidance to the notes.
     *
     * @param  list<int>  $points  The positions of the points to keep
     *
     * @throws ValidationException when there is nothing to keep.
     */
    public function handle(DeveloperReview $review, array $points): DeveloperReview
    {
        $guidance = $review->answer['guidance'] ?? [];
        $kept = array_values(array_intersect_key($guidance, array_flip($points)));

        if ($review->guidance_kept_at !== null || $kept === []) {
            throw ValidationException::withMessages(['points' => __('Choose the guidance to keep.')]);
        }

        $project = $review->project;
        $branch = $project->branch();
        $source = ' ('.($review->developer->name ?? __('our developer')).', '.now()->toFormattedDayDateString().')';

        DB::transaction(function () use ($review, $project, $branch, $kept, $source) {
            $notes = NotesDocument::parse($this->notes->files($project, $branch)[ProjectContext::PROJECT_FILE] ?? '# '.$project->name."\n");
            $lines = array_map(fn (string $point) => '- '.rtrim($point).$source, $kept);
            $section = trim(($notes->section(UpdateProjectNotes::GUIDANCE_SECTION) ?? '')."\n".implode("\n", $lines));

            $this->notes->put($project, $branch, [ProjectContext::PROJECT_FILE => $notes->withSection(UpdateProjectNotes::GUIDANCE_SECTION, $section)->toMarkdown()]);
            $review->update(['guidance_kept_at' => now()]);
        });

        return $review;
    }
}
