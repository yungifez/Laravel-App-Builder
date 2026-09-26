<?php

namespace App\Actions\Context;

use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Models\Project;

class RecordDecision
{
    /**
     * The project notes section the owner's decisions live in.
     */
    public const SECTION = 'Decisions';

    public function __construct(private ProjectNotes $notes, private UpdateProjectNotes $updateProjectNotes) {}

    /**
     * Write an owner's answer into the project notes as a decision, so it
     * is never asked again and every later change follows it. It is saved
     * at once: the owner decided it, whether or not they keep the change.
     *
     * @return string The notes' new version
     */
    public function handle(Project $project, string $question, string $answer, ?string $branch = null): string
    {
        $branch ??= $project->branch();
        $notes = NotesDocument::parse($this->notes->files($project, $branch)[ProjectContext::PROJECT_FILE] ?? '# '.$project->name."\n");
        $decisions = trim((string) $notes->section(self::SECTION));

        return $this->updateProjectNotes->handle(
            $project,
            'section:'.self::SECTION,
            trim($decisions."\n- {$question} {$answer}"),
            $this->notes->version($project, $branch),
            $branch,
        );
    }
}
