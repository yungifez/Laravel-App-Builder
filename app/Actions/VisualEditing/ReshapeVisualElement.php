<?php

namespace App\Actions\VisualEditing;

use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\NewPart;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateElement;
use App\VisualEditing\TemplateOrder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReshapeVisualElement
{
    public function __construct(
        private ProjectRepository $repository,
        private FollowLocation $followLocation,
    ) {}

    /**
     * Put a copy of one element right after it, a new part right after it,
     * or take it out of the page, and commit the file; the commit rebuilds
     * the preview. No model is involved: the element's lines are copied or
     * taken out as they are, and a new part starts as plain markup.
     *
     * @param  string  $change  VisualEdit::DUPLICATE, VisualEdit::ADD or VisualEdit::REMOVE
     * @param  string|null  $part  The kind of new part, for VisualEdit::ADD (see NewPart)
     * @return array{edit: VisualEdit, location: SourceLocation} The saved change, and the copy, the new part, or the element that held the removed one
     *
     * @throws ValidationException when the change cannot be made in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, string $change, string $revision, ?string $part = null): array
    {
        $project = $preview->project;

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('This preview cannot be edited.')]);
        }

        // The element is where the running preview says it is; the owner
        // may be editing a newer version while it rebuilds.
        if ($preview->revision !== null) {
            $location = $this->followLocation->handle($project, $preview->revision, $revision, $location);

            if ($location === null) {
                throw ValidationException::withMessages(['edit' => __('Your last change is still going in. Try again in a moment.')]);
            }
        }

        $refused = match ($change) {
            VisualEdit::DUPLICATE => __('This part cannot be copied here. Ask me to copy it instead.'),
            VisualEdit::ADD => __('Nothing can be added after this part here. Ask me to add it instead.'),
            default => __('This part cannot be removed here. Ask me to remove it instead.'),
        };
        $contents = $this->repository->show($project, $revision, $location->file);
        $offset = $contents === null ? null : TemplateElement::offset($contents, $location->line, $location->column);
        $element = $offset === null ? null : TemplateElement::atOffset((string) $contents, $offset);

        if ($contents === null || $offset === null || $element === null) {
            throw ValidationException::withMessages(['edit' => $refused]);
        }

        try {
            $changed = match ($change) {
                VisualEdit::DUPLICATE => TemplateOrder::duplicate($contents, $offset),
                VisualEdit::ADD => TemplateOrder::insertAfter($contents, $offset, NewPart::markup((string) $part)),
                default => TemplateOrder::remove($contents, $offset),
            };
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['edit' => $refused]);
        }

        [$line, $column] = TemplateOrder::position($changed['contents'], $changed['offset']);
        $now = new SourceLocation($location->file, $line, $column, $change === VisualEdit::REMOVE ? false : $location->instance);
        $tag = $change === VisualEdit::ADD ? NewPart::tag((string) $part) : $element->tag;

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $changed['contents']],
                match ($change) {
                    VisualEdit::DUPLICATE => "Copy <{$element->tag}>",
                    VisualEdit::ADD => "Add <{$tag}> after <{$element->tag}>",
                    default => "Remove <{$element->tag}>",
                }."\n\nIn {$location}.",
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        $classes = $change === VisualEdit::ADD
            ? (TemplateElement::atOffset($changed['contents'], $changed['offset'])->classes['value'] ?? '')
            : ($element->classes['value'] ?? '');

        // The new commit rebuilds the editable preview (ProjectCommitted).
        $edit = $project->visualEdits()->create([
            'experiment_id' => $project->experiment_id,
            'user_id' => $owner->id,
            'file' => $location->file,
            'line' => $line,
            'column' => $column,
            'tag' => $tag,
            'device' => 'base',
            'changes' => [$change => array_filter(['from' => (string) $location, 'part' => $part])],
            'classes_before' => $classes,
            'classes_after' => $classes,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);

        return ['edit' => $edit, 'location' => $now];
    }
}
