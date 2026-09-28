<?php

namespace App\Actions\VisualEditing;

use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
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
     * Put a copy of one element right after it, or take it out of the page,
     * and commit the file; the commit rebuilds the preview. No model is
     * involved: the element's lines are copied or taken out as they are.
     *
     * @param  string  $change  VisualEdit::DUPLICATE or VisualEdit::REMOVE
     * @return array{edit: VisualEdit, location: SourceLocation} The saved change, and the copy or the element that held the removed one
     *
     * @throws ValidationException when the change cannot be made in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, string $change, string $revision): array
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

        $refused = $change === VisualEdit::DUPLICATE
            ? __('This part cannot be copied here. Ask me to copy it instead.')
            : __('This part cannot be removed here. Ask me to remove it instead.');
        $contents = $this->repository->show($project, $revision, $location->file);
        $offset = $contents === null ? null : TemplateElement::offset($contents, $location->line, $location->column);
        $element = $offset === null ? null : TemplateElement::atOffset((string) $contents, $offset);

        if ($contents === null || $offset === null || $element === null) {
            throw ValidationException::withMessages(['edit' => $refused]);
        }

        try {
            $changed = $change === VisualEdit::DUPLICATE
                ? TemplateOrder::duplicate($contents, $offset)
                : TemplateOrder::remove($contents, $offset);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['edit' => $refused]);
        }

        [$line, $column] = TemplateOrder::position($changed['contents'], $changed['offset']);
        $now = new SourceLocation($location->file, $line, $column, $change === VisualEdit::DUPLICATE ? $location->instance : false);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $changed['contents']],
                ($change === VisualEdit::DUPLICATE ? "Copy <{$element->tag}>" : "Remove <{$element->tag}>")."\n\nIn {$location}.",
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        $classes = $element->classes['value'] ?? '';

        // The new commit rebuilds the editable preview (ProjectCommitted).
        $edit = $project->visualEdits()->create([
            'experiment_id' => $project->experiment_id,
            'user_id' => $owner->id,
            'file' => $location->file,
            'line' => $line,
            'column' => $column,
            'tag' => $element->tag,
            'device' => 'base',
            'changes' => [$change => ['from' => (string) $location]],
            'classes_before' => $classes,
            'classes_after' => $classes,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);

        return ['edit' => $edit, 'location' => $now];
    }
}
