<?php

namespace App\Actions\VisualEditing;

use App\Jobs\RebuildPreview;
use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\SourceLocation;
use App\VisualEditing\TailwindClasses;
use App\VisualEditing\TemplateElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ApplyVisualEdit
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Change how one element looks on one device, commit the file to the
     * project, and rebuild the preview. No model is involved: the classes
     * are rewritten in place, keeping every class the edit does not touch.
     *
     * The owner edits what the inspector showed them at "revision". When the
     * project moved on since, the edit is refused so nothing is overwritten.
     *
     * @param  array<string, int|float|string|null>  $changes  Property values, in pixels and words
     *
     * @throws ValidationException when the edit cannot be made in place.
     */
    public function handle(Preview $preview, User $owner, SourceLocation $location, string $revision, string $device, array $changes): VisualEdit
    {
        $project = $preview->project;

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('This preview cannot be edited.')]);
        }

        $contents = $this->repository->show($project, $revision, $location->file);
        $element = $contents === null ? null : TemplateElement::at($contents, $location->line, $location->column);

        if ($element === null || ! $element->editable()) {
            throw ValidationException::withMessages(['edit' => __('This part cannot be changed here. Ask me to change it instead.')]);
        }

        $before = $element->classes['value'] ?? '';

        try {
            $after = TailwindClasses::write($before, $device, $changes);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['edit' => __('That value cannot be used here.')]);
        }

        if ($after === $before) {
            throw ValidationException::withMessages(['edit' => __('Nothing changed.')]);
        }

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $revision,
                [$location->file => $element->withClasses($contents, $after)],
                $this->message($element, $location, $device),
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
        }

        return DB::transaction(function () use ($preview, $project, $owner, $location, $element, $device, $changes, $before, $after, $revision, $sha) {
            $edit = $project->visualEdits()->create([
                'user_id' => $owner->id,
                'file' => $location->file,
                'line' => $location->line,
                'column' => $location->column,
                'tag' => $element->tag,
                'device' => $device,
                'changes' => $changes,
                'classes_before' => $before,
                'classes_after' => $after,
                'base_revision' => $revision,
                'commit_sha' => $sha,
            ]);

            RebuildPreview::dispatch($preview)->afterCommit();

            return $edit;
        });
    }

    /**
     * Describe the edit in the commit message.
     */
    protected function message(TemplateElement $element, SourceLocation $location, string $device): string
    {
        $on = match ($device) {
            'md' => ' on tablets and up',
            'lg' => ' on desktops',
            default => '',
        };

        return "Change how <{$element->tag}> looks{$on}\n\nEdited in the inspector at {$location}.\nBuilder-Visual-Edit: yes";
    }
}
