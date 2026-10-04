<?php

namespace App\VisualEditing;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A sample page to try the designer on, outside any app.
 *
 * Each visitor gets their own copy in their session, made fresh on every
 * visit. Edits change it with the same class, motion and word rewriting a
 * real app's edits use, so the panel and the page behave as they do in the
 * builder. Nothing is committed, built or kept, and no model is called.
 */
class SampleDesign
{
    /** Where the sample says it is written, as an app's page would. */
    public const FILE = 'resources/views/bookings.blade.php';

    /** How many edits a visitor's copy remembers for undo. */
    protected const REMEMBERED = 30;

    public function __construct(private Session $session) {}

    /**
     * Start again from the sample as it ships.
     */
    public function start(): void
    {
        $this->session->put('sample-design', [
            'version' => 1,
            'contents' => File::get(resource_path('designer-sample/bookings.blade.php')),
            'edits' => [],
        ]);
    }

    /**
     * The page as the designer's frame shows it: each element stamped with
     * where it is written, as an app's preview build stamps them.
     */
    public function page(): string
    {
        $contents = $this->contents();

        preg_match_all('/<([A-Za-z][\w-]*)/', $contents, $matches, PREG_OFFSET_CAPTURE);

        // The line and column are those of the element's "<", as the
        // preview build stamps them (resources/preview-tools/locate-sources.mjs).
        foreach (array_reverse($matches[0]) as [$opening, $offset]) {
            $line = substr_count($contents, "\n", 0, $offset) + 1;
            $newline = strrpos(substr($contents, 0, $offset), "\n");
            $column = $newline === false ? $offset + 1 : $offset - $newline;
            $at = $offset + strlen($opening);
            $contents = substr_replace($contents, ' data-builder-source="'.self::FILE.':'.$line.':'.$column.'"', $at, 0);
        }

        return $contents;
    }

    /**
     * The sample's stylesheet, which the frame hands to Tailwind.
     */
    public function stylesheet(): string
    {
        return File::get(resource_path('designer-sample/app.css'));
    }

    /**
     * The sample on show, as the designer reads an app's preview. The frame
     * is served beside the designer, so its origin is the visitor's own.
     *
     * @return array<string, mixed>
     */
    public function preview(string $origin): array
    {
        return [
            'id' => 'sample',
            'status' => 'ready',
            'error' => null,
            'origin' => $origin,
            'revision' => $this->revision(),
            'updating' => false,
        ];
    }

    /**
     * The sample's colours, as the designer reads an app's.
     *
     * @return list<array{name: string, variable: string, classes: bool}>
     */
    public function colors(): array
    {
        return ThemeColors::discover([$this->stylesheet()]);
    }

    /**
     * Describe the picked part as the designer inspects one in an app.
     *
     * @return array<string, mixed>|null
     */
    public function inspect(?string $target): ?array
    {
        try {
            $location = SourceLocation::parse((string) $target);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($location->file !== self::FILE) {
            return null;
        }

        $element = TemplateElement::at($this->contents(), $location->line, $location->column);
        $classes = $element?->classes['value'] ?? '';

        return [
            'target' => (string) $location,
            'file' => $location->file,
            'line' => $location->line,
            'tag' => $element->tag ?? null,
            'instance' => false,
            'shared' => null,
            'editable' => $element?->editable() ?? false,
            'reason' => match (true) {
                $element === null => 'not_found',
                ! $element->editable() => 'dynamic',
                default => null,
            },
            'link' => null,
            'picture' => null,
            'classes' => $classes,
            'values' => TailwindClasses::effective($classes, $this->names()),
            'motion' => [...MotionClasses::read($classes), 'suggested' => MotionClasses::suggested($element->tag ?? null)],
            'area' => null,
            'origin' => null,
            'revision' => $this->revision(),
        ];
    }

    /**
     * Change how a part looks on one screen size.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws ValidationException when the part cannot take the change.
     */
    public function look(string $target, string $expected, string $device, array $changes): void
    {
        [$element, $contents, $location] = $this->element($target);
        $before = $element->classes['value'] ?? '';

        if (! TailwindClasses::same($before, $expected)) {
            throw ValidationException::withMessages(['edit' => __('This part was changed since you picked it. Pick it again to see how it looks now.')]);
        }

        try {
            $after = TailwindClasses::write($before, $device, $changes, $this->names(), TailwindTheme::discover([$this->stylesheet()]));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['edit' => __('That value cannot be used here.')]);
        }

        // Anyone can send these, and the page is shown back as it is.
        if (preg_match('/["<>\'`]/', $after) === 1) {
            throw ValidationException::withMessages(['edit' => __('That value cannot be used here.')]);
        }

        if ($after === $before) {
            throw ValidationException::withMessages(['edit' => __('Nothing changed.')]);
        }

        $this->record($location, $element, $device, 'look', array_keys($changes), $before, $after, $element->withClasses($contents, $after));
    }

    /**
     * Change how a part moves.
     *
     * @param  array{entrance: string, speed: string, wait: string, hover: string, loop: string}  $motion
     *
     * @throws ValidationException when the part cannot take the change.
     */
    public function motion(string $target, string $expected, array $motion): void
    {
        [$element, $contents, $location] = $this->element($target);
        $before = $element->classes['value'] ?? '';

        if (! TailwindClasses::same($before, $expected)) {
            throw ValidationException::withMessages(['edit' => __('This part was changed since you picked it. Pick it again to see how it looks now.')]);
        }

        try {
            $after = MotionClasses::write($before, $motion);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['edit' => __('That value cannot be used here.')]);
        }

        if (TailwindClasses::same($before, $after)) {
            throw ValidationException::withMessages(['edit' => __('Nothing changed.')]);
        }

        $this->record($location, $element, 'base', 'motion', [], $before, $after, $element->withClasses($contents, $after));
    }

    /**
     * Change the words a part shows.
     *
     * @throws ValidationException when the part's words cannot change here.
     */
    public function words(string $target, string $before, string $after): void
    {
        [$element, $contents, $location] = $this->element($target, editable: false);
        $words = TemplateText::inside($contents, $element);

        if ($words === null) {
            throw ValidationException::withMessages(['edit' => __('These words cannot be changed here.')]);
        }

        if (TemplateText::shown($words['value']) !== TemplateText::shown($before)) {
            throw ValidationException::withMessages(['edit' => __('These words were changed since. Look again and try once more.')]);
        }

        $classes = $element->classes['value'] ?? '';
        $changed = substr_replace($contents, TemplateText::written($after), $words['offset'], $words['length']);

        $this->record($location, $element, 'base', 'text', [], $classes, $classes, $changed, [TemplateText::shown($words['value']), $after]);
    }

    /**
     * Undo an edit, or do it again.
     *
     * @throws ValidationException when the page changed since in a way the edit would undo.
     */
    public function step(string $id, bool $undo): void
    {
        $state = $this->state();
        $at = collect($state['edits'])->search(fn (array $edit) => $edit['id'] === $id);
        $edit = $at === false ? null : $state['edits'][$at];

        if ($edit === null || ($edit['reverted_at'] !== null) === $undo) {
            throw ValidationException::withMessages(['edit' => $undo ? __('This change was already undone.') : __('This change is already in place.')]);
        }

        // Undo and redo go one at a time, newest first, so the page is
        // exactly as the edit left it, or as it found it.
        if ($state['contents'] !== ($undo ? $edit['after'] : $edit['before'])) {
            throw ValidationException::withMessages(['edit' => __('This part was changed since, so going back would lose that change.')]);
        }

        $state['version']++;
        $state['contents'] = $undo ? $edit['before'] : $edit['after'];
        $state['edits'][$at] = [
            ...$edit,
            'reverted_at' => $undo ? now()->toIso8601String() : null,
            'revert' => $undo ? $this->revision($state['version']) : null,
            'commit' => $undo ? $edit['commit'] : $this->revision($state['version']),
        ];

        $this->session->put('sample-design', $state);
    }

    /**
     * The visitor's edits, newest first, as the designer reads an app's.
     *
     * @return list<array<string, mixed>>
     */
    public function edits(): array
    {
        $names = $this->names();

        return array_map(fn (array $edit) => [
            'id' => $edit['id'],
            'tag' => $edit['tag'],
            'device' => $edit['device'],
            'kind' => $edit['kind'],
            'properties' => $edit['properties'],
            'words' => $edit['words'][1] ?? null,
            'words_before' => $edit['words'][0] ?? null,
            'link' => null,
            'picture' => null,
            'picture_before' => null,
            'theme' => null,
            'classes' => $edit['reverted_at'] === null ? $edit['classes_after'] : $edit['classes_before'],
            'revision' => $edit['reverted_at'] === null ? $edit['commit'] : $edit['revert'],
            'base' => $edit['base'],
            'commit' => $edit['commit'],
            'removed' => null,
            'target' => $edit['target'],
            'sides' => $edit['kind'] !== 'look' ? null : [
                'before' => ['classes' => $edit['classes_before'], 'values' => array_map(fn (array $value) => $value['value'], TailwindClasses::effective($edit['classes_before'], $names)[$edit['device']] ?? [])],
                'after' => ['classes' => $edit['classes_after'], 'values' => array_map(fn (array $value) => $value['value'], TailwindClasses::effective($edit['classes_after'], $names)[$edit['device']] ?? [])],
            ],
            'created_at' => $edit['created_at'],
            'reverted_at' => $edit['reverted_at'],
        ], array_slice($this->state()['edits'], 0, 10));
    }

    /**
     * Find the picked part in the visitor's copy.
     *
     * @return array{TemplateElement, string, SourceLocation}
     *
     * @throws ValidationException when it is not a part of the sample.
     */
    protected function element(string $target, bool $editable = true): array
    {
        try {
            $location = SourceLocation::parse($target);
        } catch (InvalidArgumentException) {
            $location = null;
        }

        $contents = $this->contents();
        $element = $location?->file === self::FILE ? TemplateElement::at($contents, $location->line, $location->column) : null;

        if ($location === null || $element === null || ($editable && ! $element->editable())) {
            throw ValidationException::withMessages(['edit' => __('This part cannot be changed here.')]);
        }

        return [$element, $contents, $location];
    }

    /**
     * Keep an edit in the visitor's copy.
     *
     * @param  list<string>  $properties
     * @param  array{string, string}|null  $words
     */
    protected function record(SourceLocation $location, TemplateElement $element, string $device, string $kind, array $properties, string $before, string $after, string $contents, ?array $words = null): void
    {
        $state = $this->state();
        $base = $this->revision($state['version']);

        $state['version']++;

        // A new edit ends what can be redone, as in any editor.
        $kept = array_values(array_filter($state['edits'], fn (array $edit) => $edit['reverted_at'] === null));

        array_unshift($kept, [
            'id' => (string) Str::uuid(),
            'tag' => $element->tag,
            'device' => $device,
            'kind' => $kind,
            'properties' => $properties,
            'words' => $words,
            'classes_before' => $before,
            'classes_after' => $after,
            'before' => $state['contents'],
            'after' => $contents,
            'base' => $base,
            'commit' => $this->revision($state['version']),
            'revert' => null,
            'target' => (string) $location,
            'created_at' => now()->toIso8601String(),
            'reverted_at' => null,
        ]);

        $state['contents'] = $contents;
        $state['edits'] = array_slice($kept, 0, self::REMEMBERED);

        $this->session->put('sample-design', $state);
    }

    /**
     * The names of the sample's colours that classes can use.
     *
     * @return list<string>
     */
    protected function names(): array
    {
        return array_column(array_filter($this->colors(), fn (array $color) => $color['classes']), 'name');
    }

    protected function contents(): string
    {
        return $this->state()['contents'];
    }

    protected function revision(?int $version = null): string
    {
        return 'sample-'.($version ?? $this->state()['version']);
    }

    /**
     * @return array{version: int, contents: string, edits: list<array<string, mixed>>}
     */
    protected function state(): array
    {
        if (! $this->session->has('sample-design')) {
            $this->start();
        }

        return $this->session->get('sample-design');
    }
}
