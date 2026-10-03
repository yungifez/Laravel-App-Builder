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
     * Something I decided that the owner said to keep has no question.
     * A new answer to a question asked before replaces the old one.
     *
     * @return string The notes' new version
     */
    public function handle(Project $project, ?string $question, string $answer, ?string $branch = null): string
    {
        $branch ??= $project->branch();
        $before = $this->lines($project, $branch);
        $lines = $question === null ? $before : array_filter($before, fn (string $line) => ! str_starts_with($line, "- {$question} "));
        $after = [...$lines, '- '.ltrim("{$question} {$answer}")];

        // The same answer again changes nothing, so nothing is written.
        return $after === $before ? $this->notes->version($project, $branch) : $this->write($project, $after, $branch);
    }

    /**
     * Take a decision back out of the notes, when the owner changed their
     * mind. Nothing is written when the notes do not hold it.
     */
    public function forget(Project $project, ?string $question, string $answer, ?string $branch = null): void
    {
        $branch ??= $project->branch();
        $lines = $this->lines($project, $branch);
        $kept = array_filter($lines, fn (string $line) => $line !== '- '.ltrim("{$question} {$answer}"));

        if (count($kept) !== count($lines)) {
            $this->write($project, $kept, $branch);
        }
    }

    /**
     * Get the lines of the decisions section.
     *
     * @return list<string>
     */
    protected function lines(Project $project, string $branch): array
    {
        $notes = NotesDocument::parse($this->notes->files($project, $branch)[ProjectContext::PROJECT_FILE] ?? '# '.$project->name."\n");
        $decisions = trim((string) $notes->section(self::SECTION));

        return $decisions === '' ? [] : array_map(rtrim(...), preg_split('/\R/', $decisions) ?: []);
    }

    /**
     * Write the decisions section.
     *
     * @param  array<string>  $lines
     * @return string The notes' new version
     */
    protected function write(Project $project, array $lines, string $branch): string
    {
        return $this->updateProjectNotes->handle(
            $project,
            'section:'.self::SECTION,
            trim(implode("\n", $lines)),
            $this->notes->version($project, $branch),
            $branch,
        );
    }
}
