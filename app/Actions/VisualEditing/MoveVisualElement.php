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
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateElement;
use App\VisualEditing\TemplateOrder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MoveVisualElement
{
    public function __construct(
        private ProjectRepository $repository,
        private FollowLocation $followLocation,
        private FormattedRevisions $formatted,
        private DesignDrafts $designDrafts,
    ) {}

    /**
     * Move one element to just before or after a sibling (the owner dragged
     * it there) and commit the file; the commit rebuilds the preview. No
     * model is involved: the element's lines are moved as they are.
     *
     * Both places must be in the same file at "revision", the version the
     * owner saw, and be siblings there. A list drawn from data repeats one
     * element, so its items cannot be reordered this way.
     *
     * @return array{edit: VisualEdit, location: SourceLocation} The saved move, and where the element is now
     *
     * @throws ValidationException when the move cannot be made in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, SourceLocation $target, string $placement, string $revision): array
    {
        $project = $preview->project;
        // Formatting since the owner's version changed nothing they see.
        $revision = $this->formatted->latest($project, $revision);

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('This preview cannot be edited.')]);
        }

        // An edit on the app waits in a draft until the owner keeps it.
        $this->designDrafts->open($preview, $owner);

        if ($location->file !== $target->file) {
            throw ValidationException::withMessages(['edit' => __('These parts are built in different places, so I can\'t move one here. Ask me to move it instead.')]);
        }

        // Both places are where the running preview says they are; the owner
        // may be editing a newer version while it rebuilds.
        if ($preview->revision !== null) {
            $location = $this->followLocation->handle($project, $preview->revision, $revision, $location);
            $target = $this->followLocation->handle($project, $preview->revision, $revision, $target);

            if ($location === null || $target === null) {
                throw ValidationException::withMessages(['edit' => __('Your last change is still going in. Try again in a moment.')]);
            }
        }

        $contents = $this->repository->show($project, $revision, $location->file);
        $offset = $contents === null ? null : TemplateElement::offset($contents, $location->line, $location->column);
        $targetOffset = $contents === null ? null : TemplateElement::offset($contents, $target->line, $target->column);
        $element = $offset === null ? null : TemplateElement::atOffset((string) $contents, $offset);

        if ($contents === null || $element === null || $targetOffset === null) {
            throw ValidationException::withMessages(['edit' => __('This part cannot be moved here. Ask me to move it instead.')]);
        }

        try {
            $moved = TemplateOrder::move($contents, $offset, $targetOffset, $placement);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['edit' => __('This part cannot be moved there. Ask me to move it instead.')]);
        }

        [$line, $column] = TemplateOrder::position($moved['contents'], $moved['offset']);
        $now = new SourceLocation($location->file, $line, $column, $location->instance);

        $name = ElementName::for($element->tag);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $moved['contents']],
                "Move {$name} {$placement} another part\n\nIn {$location}, now at {$now}.",
                ['name' => $owner->name, 'email' => $owner->email],
                $preview->branch(),
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        $classes = $element->classes['value'] ?? '';

        // The new commit rebuilds the editable preview (ProjectCommitted).
        $edit = $project->visualEdits()->create([
            'experiment_id' => $project->experiment_id,
            'feature_request_id' => $preview->designing()?->id,
            'user_id' => $owner->id,
            'file' => $location->file,
            'line' => $line,
            'column' => $column,
            'tag' => $element->tag,
            'device' => 'base',
            'changes' => [VisualEdit::MOVE => ['placement' => $placement, 'target' => (string) $target, 'from' => (string) $location]],
            'classes_before' => $classes,
            'classes_after' => $classes,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);

        return ['edit' => $edit, 'location' => $now];
    }
}
