<?php

namespace App\Actions\VisualEditing;

use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TemplateElement;
use App\VisualEditing\TemplateLink;
use App\VisualEditing\TemplatePicture;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ChangeVisualPicture
{
    public function __construct(
        private ProjectRepository $repository,
        private FollowLocation $followLocation,
    ) {}

    /**
     * Put a new picture in place of one on the page: keep the file in the
     * app's public folder and point the picture at it, in one commit that
     * rebuilds the preview.
     *
     * The picture must still show "before" at "revision", so a newer change
     * to it is never overwritten. Undo puts back only the page; the kept
     * file stays, so redo can show it again.
     *
     * @throws ValidationException when the picture cannot be changed in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, string $before, UploadedFile $picture, string $revision): VisualEdit
    {
        $project = $preview->project;

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
        $source = $element === null ? null : TemplatePicture::in((string) $contents, $element);

        if ($contents === null || $element === null || $source === null) {
            throw ValidationException::withMessages(['edit' => __('Your app decides which picture shows here, so I can\'t change it here. Ask me to change it instead.')]);
        }

        if ($source['value'] !== $before) {
            throw ValidationException::withMessages(['edit' => __('This picture was changed since. Look again and try once more.')]);
        }

        $file = (string) $picture->get();
        $path = TemplatePicture::path($file, $picture->extension());
        $address = TemplatePicture::address($path);
        $changed = substr_replace($contents, TemplateLink::written($address), $source['offset'], $source['length']);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$path => $file, $location->file => $changed],
                "Show a new picture in <{$element->tag}>\n\nIn {$location}.",
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
            'changes' => [VisualEdit::PICTURE => ['before' => $source['value'], 'after' => $address]],
            'classes_before' => $classes,
            'classes_after' => $classes,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);
    }
}
