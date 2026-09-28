<?php

namespace App\Actions\VisualEditing;

use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\QuotedWords;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateElement;
use App\VisualEditing\TemplateText;
use Illuminate\Validation\ValidationException;

class ChangeVisualText
{
    public function __construct(
        private ProjectRepository $repository,
        private FollowLocation $followLocation,
    ) {}

    /**
     * Change the words inside one element (the owner typed over them in the
     * app) and commit the file; the commit rebuilds the preview. No model is
     * involved: only the words written in the template change.
     *
     * The element must still show "before", the words the owner saw, at
     * "revision", so a newer change to them is never overwritten.
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

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('This preview cannot be edited.')]);
        }

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

        if ($contents !== null && $element !== null && $words === null && TemplateText::named($contents, $element) !== null) {
            return $this->changeWhereWritten($preview, $owner, $location, $element, $before, $after, $revision, $places);
        }

        if ($contents === null || $element === null || $words === null) {
            throw ValidationException::withMessages(['edit' => __('These words come from your app\'s data or code, so I can\'t change them here. Ask me to change them instead.')]);
        }

        if (TemplateText::shown($words['value']) !== TemplateText::shown($before)) {
            throw ValidationException::withMessages(['edit' => __('These words were changed since. Look again and try once more.')]);
        }

        $changed = substr_replace($contents, TemplateText::written($after), $words['offset'], $words['length']);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $changed],
                "Change the words in <{$element->tag}>\n\nIn {$location}.",
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        $classes = $element->classes['value'] ?? '';

        // The new commit rebuilds the editable preview (ProjectCommitted).
        return $project->visualEdits()->create([
            'experiment_id' => $project->experiment_id,
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

            try {
                $sha = $this->repository->commitFiles(
                    $project,
                    $revision,
                    [$file => substr_replace((string) $contents, $after, $words['offset'], $words['length'])],
                    "Change the words shown in <{$element->tag}>\n\nIn {$file}:{$line}:{$column}, shown by {$location}.",
                    ['name' => $owner->name, 'email' => $owner->email],
                );
            } catch (RepositoryConflict $exception) {
                throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
            }

            $classes = $element->classes['value'] ?? '';

            // The edit is kept where the words are written, so undoing it
            // puts that file back.
            return $project->visualEdits()->create([
                'experiment_id' => $project->experiment_id,
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
}
