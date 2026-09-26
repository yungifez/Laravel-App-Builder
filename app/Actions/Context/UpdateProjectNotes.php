<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Models\Project;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateProjectNotes
{
    /**
     * The project-wide section the developer's guidance lives in.
     */
    public const GUIDANCE_SECTION = 'Engineering direction';

    public function __construct(private ProjectRepository $repository, private ReadProjectContext $readProjectContext) {}

    /**
     * Replace one part of the project's notes with the owner's text and
     * commit it, so the edit has history like any other change. A part is
     * "introduction" or "section:<heading>" in the project notes, or
     * "summary:<area>" or "rules:<area>" in an area's notes. Every other
     * part of the file stays as it was.
     *
     * The owner edits what the page showed them at "revision". When the
     * project moved on since, the edit is refused so nothing is overwritten.
     *
     * @throws ValidationException when the part is unknown, nothing changed,
     *                             the result would not read back, or the
     *                             project moved on.
     */
    public function handle(Project $project, User $owner, string $part, string $text, string $revision): string
    {
        [$kind, $name] = explode(':', $part, 2) + [1 => ''];

        $file = match ($kind) {
            'introduction', 'section' => ProjectContext::PROJECT_FILE,
            'summary', 'rules' => $this->readProjectContext->atRevision($project, $revision)->capabilities[$name]->file ?? null,
            default => null,
        };

        if ($file === null) {
            throw ValidationException::withMessages(['body' => __('These notes no longer exist. Reload the page.')]);
        }

        $before = $this->repository->show($project, $revision, $file);
        $notes = NotesDocument::parse($before ?? '# '.$project->name."\n");

        $notes = match ($kind) {
            'introduction' => $notes->withIntroduction($text),
            'section' => $notes->withSection($name, $text),
            'summary' => $notes->withSummary($text),
            default => $notes->withSection('Rules', self::bullets($text)),
        };

        $after = $notes->toMarkdown();

        if ($after === $before) {
            throw ValidationException::withMessages(['body' => __('Nothing changed.')]);
        }

        if ($file !== ProjectContext::PROJECT_FILE) {
            try {
                Capability::fromMarkdown($file, $after);
            } catch (InvalidContextFile $exception) {
                throw ValidationException::withMessages(['body' => $exception->getMessage()]);
            }
        }

        try {
            return $this->repository->commitFiles(
                $project,
                $revision,
                [$file => $after],
                $this->message($kind, $name),
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }
    }

    /**
     * Turn the owner's lines into a Markdown list, one item per line.
     */
    public static function bullets(string $text): string
    {
        $lines = array_filter(array_map(trim(...), preg_split('/\R/', $text) ?: []), fn (string $line) => $line !== '');

        return implode("\n", array_map(fn (string $line) => '- '.preg_replace('/^[-*]\s+/', '', $line), $lines));
    }

    /**
     * Describe the edit in the commit message.
     */
    protected function message(string $kind, string $name): string
    {
        $subject = match ($kind) {
            'introduction' => 'Describe what the app is for',
            'section' => Str::lower($name) === Str::lower(self::GUIDANCE_SECTION) ? 'Update the guidance from the developer' : "Update the notes on \"{$name}\"",
            'summary' => "Describe what \"{$name}\" does",
            default => "Update what must always be true in \"{$name}\"",
        };

        return "{$subject}\n\nEdited on the Understanding page.\nBuilder-Notes-Edit: yes";
    }
}
