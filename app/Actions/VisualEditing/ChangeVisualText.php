<?php

namespace App\Actions\VisualEditing;

use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\DesignDrafts;
use App\VisualEditing\ElementName;
use App\VisualEditing\FormattedRevisions;
use App\VisualEditing\QuotedWords;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateElement;
use App\VisualEditing\TemplateText;
use App\VisualEditing\TranslationKey;
use Illuminate\Validation\ValidationException;

class ChangeVisualText
{
    public function __construct(
        private ProjectRepository $repository,
        private FollowLocation $followLocation,
        private FormattedRevisions $formatted,
        private DesignDrafts $designDrafts,
    ) {}

    /**
     * Change the words inside one element (the owner typed over them in the
     * app) and commit the file; the commit rebuilds the preview. No model is
     * involved: only the words written in the template change.
     *
     * The element must still show "before", the words the owner saw, at
     * "revision", so a newer change to them is never overwritten.
     *
     * Words the element looks up as a translation are changed in the app's
     * translation file for its language, so the key stays.
     *
     * When the element shows a value through `{{ }}` (a title passed to a
     * component, or set in a page's script), the words are changed where
     * they are written instead, in the first of the element's own file and
     * "places" (the files the page is drawn from, nearest first) that
     * writes them as a quoted string.
     *
     * @param  list<string>  $places
     *
     * @throws ValidationException when the words cannot be changed in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, string $before, string $after, string $revision, array $places = []): VisualEdit
    {
        $project = $preview->project;
        // Formatting since the owner's version changed nothing they see.
        $revision = $this->formatted->latest($project, $revision);

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('This preview cannot be edited.')]);
        }

        // An edit on the app waits in a draft until the owner keeps it.
        $this->designDrafts->open($preview, $owner);

        // The part is where the running preview says it is; the owner may be
        // editing a newer version while it rebuilds.
        if ($preview->revision !== null) {
            $location = $this->followLocation->handle($project, $preview->revision, $revision, $location);

            if ($location === null) {
                throw ValidationException::withMessages(['edit' => __('Your last change is still going in. Try again in a moment.')]);
            }
        }

        $contents = $this->repository->show($project, $revision, $location->file);
        $element = $contents === null ? null : TemplateElement::at($contents, $location->line, $location->column);
        $words = $element === null ? null : TemplateText::inside((string) $contents, $element);

        $named = $contents !== null && $element !== null && $words === null ? TemplateText::named($contents, $element) : null;

        if ($element !== null && $named !== null) {
            $key = TranslationKey::in($named);

            return $key === null
                ? $this->changeWhereWritten($preview, $owner, $location, $element, $before, $after, $revision, $places)
                : $this->changeTranslation($preview, $owner, $location, $element, $key, $before, $after, $revision);
        }

        if ($contents === null || $element === null || $words === null) {
            throw ValidationException::withMessages(['edit' => __('These words come from your app\'s data or code, so I can\'t change them here. Ask me to change them instead.')]);
        }

        if (TemplateText::shown($words['value']) !== TemplateText::shown($before)) {
            throw ValidationException::withMessages(['edit' => __('These words were changed since. Look again and try once more.')]);
        }

        $changed = substr_replace($contents, TemplateText::written($after), $words['offset'], $words['length']);

        $name = ElementName::for($element->tag);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $changed],
                "Change the words in {$name}\n\nIn {$location}.",
                ['name' => $owner->name, 'email' => $owner->email],
                $preview->branch(),
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        $classes = $element->classes['value'] ?? '';

        // The new commit rebuilds the editable preview (ProjectCommitted).
        return $project->visualEdits()->create([
            'experiment_id' => $project->experiment_id,
            'feature_request_id' => $preview->designing()?->id,
            'user_id' => $owner->id,
            'file' => $location->file,
            'line' => $location->line,
            'column' => $location->column,
            'tag' => $element->tag,
            'device' => 'base',
            'changes' => [VisualEdit::TEXT => ['before' => TemplateText::shown($words['value']), 'after' => $after]],
            'classes_before' => $classes,
            'classes_after' => $classes,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);
    }

    /**
     * Change words the element shows through `{{ }}` where they are written
     * as a quoted string. Words written more than once in that file are left
     * alone: which one the page shows cannot be told.
     *
     * @param  list<string>  $places
     *
     * @throws ValidationException when the words are not written plainly anywhere.
     */
    protected function changeWhereWritten(Preview $preview, User $owner, SourceLocation $location, TemplateElement $element, string $before, string $after, string $revision, array $places): VisualEdit
    {
        $project = $preview->project;

        foreach (array_unique([$location->file, ...$places]) as $file) {
            $contents = $this->repository->show($project, $revision, $file);
            $found = $contents === null ? [] : QuotedWords::in($contents, $before);

            if ($found === []) {
                continue;
            }

            if (count($found) > 1) {
                throw ValidationException::withMessages(['edit' => __('These words are written in more than one place, so I can\'t tell which to change. Ask me to change them instead.')]);
            }

            [$words] = $found;

            if (! QuotedWords::fits($after, $words['quote'])) {
                throw ValidationException::withMessages(['edit' => __('These words can\'t hold quotes, backslashes or line breaks. Ask me to change them instead.')]);
            }

            $prefix = substr((string) $contents, 0, $words['offset']);
            $line = substr_count($prefix, "\n") + 1;
            $column = $words['offset'] - (int) strrpos("\n".$prefix, "\n") + 1;

            $name = ElementName::for($element->tag);

            try {
                $sha = $this->repository->commitFiles(
                    $project,
                    $revision,
                    [$file => substr_replace((string) $contents, $after, $words['offset'], $words['length'])],
                    "Change the words shown in {$name}\n\nIn {$file}:{$line}:{$column}, shown by {$location}.",
                    ['name' => $owner->name, 'email' => $owner->email],
                    $preview->branch(),
                );
            } catch (RepositoryConflict $exception) {
                throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
            }

            $classes = $element->classes['value'] ?? '';

            // The edit is kept where the words are written, so undoing it
            // puts that file back.
            return $project->visualEdits()->create([
                'experiment_id' => $project->experiment_id,
                'feature_request_id' => $preview->designing()?->id,
                'user_id' => $owner->id,
                'file' => $file,
                'line' => $line,
                'column' => $column,
                'tag' => $element->tag,
                'device' => 'base',
                'changes' => [VisualEdit::TEXT => ['before' => substr((string) $contents, $words['offset'], $words['length']), 'after' => $after]],
                'classes_before' => $classes,
                'classes_after' => $classes,
                'base_revision' => $revision,
                'commit_sha' => $sha,
            ]);
        }

        throw ValidationException::withMessages(['edit' => __('These words come from your app\'s data or code, so I can\'t change them here. Ask me to change them instead.')]);
    }

    /**
     * Change words the element looks up as a translation where the app's
     * language gives them: `lang/{locale}/{file}.php` for a key such as
     * `auth.failed`, else `lang/{locale}.json`. A key that is still its own
     * words gets its first entry in the JSON file. Laravel reads that file
     * for a Blade page; a script's lookup reads it only when the app already
     * keeps one.
     *
     * @throws ValidationException when the words cannot be changed there.
     */
    protected function changeTranslation(Preview $preview, User $owner, SourceLocation $location, TemplateElement $element, string $key, string $before, string $after, string $revision): VisualEdit
    {
        $project = $preview->project;
        $locale = TranslationKey::locale($this->repository->show($project, $revision, 'config/app.php'));
        $group = TranslationKey::grouped($key);
        $file = null;
        $changed = null;
        $found = null;

        if ($group !== null) {
            $file = "lang/{$locale}/{$group[0]}.php";
            $contents = $this->repository->show($project, $revision, $file);
            $found = $contents === null ? null : TranslationKey::inPhp($contents, $group[1]);
            $changed = $found === null ? null : substr_replace((string) $contents, TranslationKey::php($after), $found['offset'], $found['length']);
        }

        if ($found === null) {
            $file = "lang/{$locale}.json";
            $contents = $this->repository->show($project, $revision, $file);
            $found = $contents === null ? null : TranslationKey::inJson($contents, $key);

            if ($found !== null) {
                $changed = substr_replace((string) $contents, TranslationKey::json($after), $found['offset'], $found['length']);
            } elseif ($contents !== null || str_ends_with($location->file, '.blade.php')) {
                // Laravel shows a key with no words of its own as written.
                $changed = TranslationKey::added($contents, $key, $after);
                $entry = TranslationKey::json($key).': ';
                $found = $changed === null ? null : ['offset' => (int) strrpos($changed, $entry) + strlen($entry), 'length' => 0, 'value' => $key];
            }
        }

        if ($found === null || $changed === null) {
            throw ValidationException::withMessages(['edit' => __('These words come from your app\'s translations, but I can\'t find where. Ask me to change them instead.')]);
        }

        if (TemplateText::shown($found['value']) !== TemplateText::shown($before)) {
            throw ValidationException::withMessages(['edit' => __('These words were changed since. Look again and try once more.')]);
        }

        $prefix = substr($changed, 0, $found['offset']);
        $line = substr_count($prefix, "\n") + 1;
        $column = $found['offset'] - (int) strrpos("\n".$prefix, "\n") + 1;
        $name = ElementName::for($element->tag);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$file => $changed],
                "Change the words shown in {$name}\n\nIn {$file}, the words for \"{$key}\", shown by {$location}.",
                ['name' => $owner->name, 'email' => $owner->email],
                $preview->branch(),
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        $classes = $element->classes['value'] ?? '';

        // The edit is kept in the translation file, so undoing it puts that
        // file back.
        return $project->visualEdits()->create([
            'experiment_id' => $project->experiment_id,
            'feature_request_id' => $preview->designing()?->id,
            'user_id' => $owner->id,
            'file' => $file,
            'line' => $line,
            'column' => $column,
            'tag' => $element->tag,
            'device' => 'base',
            'changes' => [VisualEdit::TEXT => ['before' => $found['value'], 'after' => $after]],
            'classes_before' => $classes,
            'classes_after' => $classes,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);
    }
}
