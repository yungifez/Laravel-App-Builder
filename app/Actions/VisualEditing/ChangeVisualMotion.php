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
use App\VisualEditing\MotionClasses;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TailwindClasses;
use App\VisualEditing\TemplateElement;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ChangeVisualMotion
{
    public function __construct(
        private ProjectRepository $repository,
        private FollowLocation $followLocation,
        private FormattedRevisions $formatted,
        private DesignDrafts $designDrafts,
    ) {}

    /**
     * Change how one element moves, with the ready-made choices of
     * MotionClasses, and commit the file. No model is involved. The edit is
     * refused when the element changed since the owner picked it, like a
     * change to its look (ApplyVisualEdit).
     *
     * @param  array{entrance: string, speed: string, wait: string, hover: string, loop: string}  $motion
     *
     * @throws ValidationException when the edit cannot be made in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, string $revision, string $expected, array $motion): VisualEdit
    {
        $project = $preview->project;
        $revision = $this->formatted->latest($project, $revision);

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('I can\'t change this version of your app here. Ask me to change it instead.')]);
        }

        $this->designDrafts->open($preview, $owner);

        $location = $preview->revision === null ? $location : $this->followLocation->handle($project, $preview->revision, $revision, $location);

        if ($location === null) {
            throw ValidationException::withMessages(['edit' => __('Your last change is still going in. Try again in a moment.')]);
        }

        $contents = $this->repository->show($project, $revision, $location->file);
        $element = $contents === null ? null : TemplateElement::at($contents, $location->line, $location->column);

        if ($element === null || ! $element->editable()) {
            throw ValidationException::withMessages(['edit' => __('This part cannot be changed here. Ask me to change it instead.')]);
        }

        $before = $element->classes['value'] ?? '';

        if (! TailwindClasses::same($before, $expected)) {
            throw ValidationException::withMessages(['edit' => __('This part was changed since you picked it. Pick it again to see how it looks now.')]);
        }

        if (MotionClasses::read($before)['custom']) {
            throw ValidationException::withMessages(['edit' => __('This part moves in a way of its own. Ask me to change how it moves.')]);
        }

        try {
            $after = MotionClasses::write($before, $motion);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['edit' => __('That value does not fit here. Pick another one.')]);
        }

        if (TailwindClasses::same($before, $after)) {
            throw ValidationException::withMessages(['edit' => __('Nothing changed.')]);
        }

        $name = ElementName::for($element->tag);

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $element->withClasses($contents, $after)],
                "Change how {$name} moves\n\nIn {$location}.",
                ['name' => $owner->name, 'email' => $owner->email],
                $preview->branch(),
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        // Undone like a change to the look: by swapping the classes back.
        return $project->visualEdits()->create([
            'experiment_id' => $project->experiment_id,
            'feature_request_id' => $preview->designing()?->id,
            'user_id' => $owner->id,
            'file' => $location->file,
            'line' => $location->line,
            'column' => $location->column,
            'tag' => $element->tag,
            'device' => 'base',
            'changes' => [VisualEdit::MOTION => $motion],
            'classes_before' => $before,
            'classes_after' => $after,
            'base_revision' => $revision,
            'commit_sha' => $sha,
        ]);
    }
}
