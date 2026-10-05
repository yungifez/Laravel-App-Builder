<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

class UpdateProjectNotes
{
    /**
     * The project-wide section the developer's guidance lives in.
     */
    public const GUIDANCE_SECTION = 'Engineering direction';

    /**
     * The section holding what the owner wants the app to achieve. Plans
     * say how a change serves it.
     */
    public const GOAL_SECTION = 'Goal';

    public function __construct(private ProjectNotes $notes, private ReadProjectContext $readProjectContext) {}

    /**
     * Replace one part of the notes of a line of work (by default the one
     * the owner works in) with their text. A part is "introduction" or "section:<heading>" in the
     * project notes, or "summary:<area>", "rules:<area>",
     * "not_connected:<area>" or "checked:<area>" in an area's notes, or
     * "notes:<area>" for an area's whole notes file. For
     * "not_connected" the text lists the keys of the areas this one does
     * not affect, one per line; an empty text connects them all again.
     * "checked" ignores the text and records that the owner found the
     * notes still right. "notes" replaces the whole file, keeping its key
     * and what it is connected to; only we write it, from the model that
     * brings notes up to date (UpdateBehindNotes), never the owner's form.
     * Every other part of the file stays as it was.
     *
     * The owner edits the notes as they were at "version". When they
     * changed since, the edit is refused so nothing is overwritten.
     *
     * @return string The notes' new version
     *
     * @throws ValidationException when the part is unknown, nothing changed,
     *                             the result would not read back, or the
     *                             notes changed since.
     */
    public function handle(Project $project, string $part, string $text, string $version, ?string $branch = null): string
    {
        [$kind, $name] = explode(':', $part, 2) + [1 => ''];
        $branch ??= $project->branch();

        if ($this->notes->version($project, $branch) !== $version) {
            throw ValidationException::withMessages(['body' => __('The notes changed while you were editing. Try again on the updated version.')]);
        }

        $capabilities = in_array($kind, ['summary', 'rules', 'not_connected', 'checked', 'notes'], true) ? $this->readProjectContext->current($project, $branch)->capabilities : [];
        $file = match ($kind) {
            'introduction', 'section' => ProjectContext::PROJECT_FILE,
            'summary', 'rules', 'not_connected', 'checked', 'notes' => $capabilities[$name]->file ?? null,
            default => null,
        };

        if ($file === null) {
            throw ValidationException::withMessages(['body' => __('These notes no longer exist. Reload the page.')]);
        }

        $before = $this->notes->files($project, $branch)[$file] ?? null;
        $notes = NotesDocument::parse($before ?? '# '.$project->name."\n");

        $notes = match ($kind) {
            'notes' => NotesDocument::parse($text),
            'introduction' => $notes->withIntroduction($text),
            'section' => $notes->withSection($name, $text),
            'summary' => $notes->withSummary($text),
            'not_connected' => $notes->withNotConnected($this->otherAreas($text, $name)),
            'checked' => $notes->withChecked(now()),
            default => $notes->withSection('Rules', self::bullets($text)),
        };

        $after = $notes->toMarkdown();

        if ($after === $before) {
            throw ValidationException::withMessages(['body' => __('Nothing changed.')]);
        }

        if ($file !== ProjectContext::PROJECT_FILE) {
            try {
                $read = Capability::fromMarkdown($file, $after);
            } catch (InvalidContextFile $exception) {
                throw ValidationException::withMessages(['body' => $exception->getMessage()]);
            }

            // A whole file must stay the same part, connected the same way:
            // what it affects is the owner's and the analysis's to say.
            $was = $kind === 'notes' && $before !== null ? Capability::fromMarkdown($file, $before) : null;

            if ($kind === 'notes' && ($read->key !== $name || $read->effects !== $was?->effects || $read->notConnected !== $was->notConnected)) {
                throw ValidationException::withMessages(['body' => __('The new notes changed what this part is or what it is connected to.')]);
            }
        }

        $this->notes->put($project, $branch, [$file => $after]);

        return $this->notes->version($project, $branch);
    }

    /**
     * Read the keys of the areas an area is not connected to, one per line.
     * A connection may name an area the notes do not describe yet, so any
     * key the notes accept will do, except the area itself.
     *
     * @return list<string>
     *
     * @throws ValidationException
     */
    protected function otherAreas(string $text, string $area): array
    {
        $keys = array_values(array_unique(array_filter(array_map(trim(...), preg_split('/\R/', $text) ?: []), fn (string $key) => $key !== '')));

        if (in_array($area, $keys, true)) {
            throw ValidationException::withMessages(['body' => __('A part is always connected to itself.')]);
        }

        sort($keys);

        return $keys;
    }

    /**
     * Turn the owner's lines into a Markdown list, one item per line.
     */
    public static function bullets(string $text): string
    {
        $lines = array_filter(array_map(trim(...), preg_split('/\R/', $text) ?: []), fn (string $line) => $line !== '');

        return implode("\n", array_map(fn (string $line) => '- '.preg_replace('/^[-*]\s+/', '', $line), $lines));
    }
}
