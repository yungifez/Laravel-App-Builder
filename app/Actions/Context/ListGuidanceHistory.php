<?php

namespace App\Actions\Context;

use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Models\Project;
use App\Models\ProjectNoteRevision;

class ListGuidanceHistory
{
    /**
     * Get the earlier wordings of the guidance from the owner's developer,
     * newest first, with who wrote each and when (architecture §29.3). Writes
     * that left the guidance as it was are left out, and so is the wording in
     * use now.
     *
     * @return list<array{text: string|null, at: string|null, by: string|null, mine: bool}>
     */
    public function handle(Project $project, int|string|null $viewer, int $limit = 10): array
    {
        $versions = [];
        $previous = false;

        foreach ($project->noteRevisions()->with('user')->where('branch', $project->branch())->where('path', ProjectContext::PROJECT_FILE)->oldest('id')->lazy() as $revision) {
            /** @var ProjectNoteRevision $revision */
            $text = $revision->contents === null ? null : NotesDocument::parse($revision->contents)->section(UpdateProjectNotes::GUIDANCE_SECTION);

            if ($text === $previous) {
                continue;
            }

            $previous = $text;
            $versions[] = [
                'text' => $text,
                'at' => $revision->created_at?->toIso8601String(),
                'by' => $revision->user?->name,
                'mine' => $viewer !== null && $revision->user_id === (int) $viewer,
            ];
        }

        array_pop($versions);

        return array_slice(array_reverse(array_values(array_filter($versions, fn (array $version) => $version['text'] !== null))), 0, $limit);
    }
}
