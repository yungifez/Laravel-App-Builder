<?php

namespace App\Actions\VisualEditing;

use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\ElementName;
use App\VisualEditing\FormattedRevisions;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateElement;
use App\VisualEditing\TemplateLink;
use Illuminate\Validation\ValidationException;

class ChangeVisualLink
{
    public function __construct(
        private ProjectRepository $repository,
        private FollowLocation $followLocation,
        private FormattedRevisions $formatted,
    ) {}

    /**
     * Change where one link goes and commit the file; the commit rebuilds
     * the preview. No model is involved: only the address written in the
     * template changes.
     *
     * The link must still go to "before" at "revision", so a newer change to
     * it is never overwritten.
     *
     * @throws ValidationException when the address cannot be changed in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, string $before, string $after, string $revision): VisualEdit
    {
        $project = $preview->project;
        // Formatting since the owner's version changed nothing they see.
        $revision = $this->formatted->latest($project, $revision);

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('This preview cannot be edited.')]);
        }

        if ($preview->revision !== null) {
            $location = $this->followLocation->handle($project, $preview->revision, $revision, $location);

            if ($location === null) {
                throw ValidationException::withMessages(['edit' => __('Your last change is still going in. Try again in a moment.')]);
            }
        }

        $contents = $this->repository->show($project, $revision, $location->file);
        $element = $contents === null ? null : TemplateElement::at($contents, $location->line, $location->column);
        $link = $element === null ? null : TemplateLink::in((string) $contents, $element);

        if ($contents === null || $element === null || $link === null) {
            throw ValidationException::withMessages(['edit' => __('Your app decides where this link goes, so I can\'t change it here. Ask me to change it instead.')]);
        }

        if ($link['value'] !== $before) {
            throw ValidationException::withMessages(['edit' => __('This link was changed since. Look again and try once more.')]);
        }

        $changed = substr_replace($contents, TemplateLink::written($after), $link['offset'], $link['length']);

        $name = ElementName::for($element->tag);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $changed],
                "Change where {$name} goes\n\nIn {$location}.",
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
            'feature_request_id' => $preview->feature_request_id,
            'user_id' => $owner->id,
            'file' => $location->file,
            'line' => $location->line,
            'column' => $location->column,
            'tag' => $element->tag,
            'device' => 'base',
            'changes' => [VisualEdit::LINK => ['before' => $link['value'], 'after' => $after]],
            'classes_before' => $classes,
            'classes_after' => $classes,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);
    }
}
