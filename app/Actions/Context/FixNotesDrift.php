<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\NotesDocument;
use App\Context\ProjectNotes;
use App\Models\Project;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

class FixNotesDrift
{
    public function __construct(
        private ProjectNotes $notes,
        private ReadProjectContext $readProjectContext,
        private CheckProjectNotes $checkProjectNotes,
    ) {}

    /**
     * Put right what the quick check found in the notes of the line the
     * owner works in: take the items it named out of that part. Only items
     * the check still finds are taken out, so a file that is back in the
     * app stays in the notes. No model is involved.
     *
     * The owner fixes the notes as they were at "version". When they
     * changed since, nothing is changed.
     *
     * @param  list<string>  $remove  The items the owner saw
     * @return string The notes' new version
     *
     * @throws ValidationException when the notes changed since, or the
     *                             check no longer finds these items.
     */
    public function handle(Project $project, string $part, array $remove, string $version): string
    {
        $branch = $project->branch();

        if ($this->notes->version($project, $branch) !== $version) {
            throw ValidationException::withMessages(['fix' => __('The notes changed since you checked. Check your app again.')]);
        }

        $found = collect($this->checkProjectNotes->handle($project))->firstWhere('fix.part', $part);
        $remove = array_values(array_intersect($remove, $found['fix']['remove'] ?? []));
        [$field, $key] = explode(':', $part, 2);
        $file = $this->readProjectContext->current($project, $branch)->capabilities[$key]->file ?? null;

        if ($remove === [] || $file === null) {
            throw ValidationException::withMessages(['fix' => __('This is already fixed. Check your app again.')]);
        }

        $before = (string) ($this->notes->files($project, $branch)[$file] ?? '');
        $notes = NotesDocument::parse($before);
        $items = $notes->frontmatter === '' ? [] : (array) (Yaml::parse(substr($notes->frontmatter, 4, -4))[$field] ?? []);

        $kept = array_values(Arr::reject($items, fn (mixed $item) => in_array(match ($field) {
            'effects' => $item['to'] ?? null,
            'behaviors' => $item['key'] ?? null,
            default => $item,
        }, $remove, true)));
        $after = $this->withList($notes, $field, $kept)->toMarkdown();

        if ($kept === $items) {
            throw ValidationException::withMessages(['fix' => __('This is already fixed. Check your app again.')]);
        }

        try {
            Capability::fromMarkdown($file, $after);
        } catch (InvalidContextFile $exception) {
            throw ValidationException::withMessages(['fix' => $exception->getMessage()]);
        }

        $this->notes->put($project, $branch, [$file => $after]);

        return $this->notes->version($project, $branch);
    }

    /**
     * Get a copy of the notes with one frontmatter list replaced, or
     * removed when empty. The other fields keep their formatting.
     *
     * @param  list<mixed>  $items
     */
    protected function withList(NotesDocument $notes, string $field, array $items): NotesDocument
    {
        $fields = substr($notes->frontmatter, 4, -4);
        $block = $items === [] ? '' : Yaml::dump([$field => $items], 4, 4);
        $pattern = '/^'.preg_quote($field, '/').':.*\n(?:(?:[ \t]+|-).*\n)*/m';
        $fields = (string) preg_replace($pattern, addcslashes($block, '\\$'), $fields, 1);

        return new NotesDocument($fields === '' ? '' : "---\n{$fields}---\n", $notes->title, $notes->introduction, $notes->sections);
    }
}
