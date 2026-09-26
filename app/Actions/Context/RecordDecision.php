<?php

namespace App\Actions\Context;

use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;

class RecordDecision
{
    /**
     * The project notes section the owner's decisions live in.
     */
    public const SECTION = 'Decisions';

    public function __construct(private ProjectRepository $repository, private UpdateProjectNotes $updateProjectNotes) {}

    /**
     * Write an owner's answer into the project notes as a decision, so it
     * is never asked again and every later change follows it. It is its own
     * commit: the owner decided it, whether or not they keep the change.
     */
    public function handle(Project $project, User $owner, string $question, string $answer): string
    {
        $this->repository->import($project);
        $head = $this->repository->head($project);
        $notes = NotesDocument::parse($this->repository->show($project, $head, ProjectContext::PROJECT_FILE) ?? '# '.$project->name."\n");
        $decisions = trim((string) $notes->section(self::SECTION));

        return $this->updateProjectNotes->handle(
            $project,
            $owner,
            'section:'.self::SECTION,
            trim($decisions."\n- {$question} {$answer}"),
            $head,
        );
    }
}
