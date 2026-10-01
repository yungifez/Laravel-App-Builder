<?php

namespace App\Actions\VisualEditing;

use App\Models\Preview;
use App\Models\User;
use App\Models\VisualEdit;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\VisualEditing\FormattedRevisions;
use App\VisualEditing\ThemeColors;
use Illuminate\Validation\ValidationException;

class ChangeThemeColor
{
    public function __construct(
        private ProjectRepository $repository,
        private FormattedRevisions $formatted,
    ) {}

    /**
     * Change one of the app's theme colours for its light or dark look and
     * commit the stylesheet; the commit rebuilds the preview. Every part
     * drawn in that colour changes with it.
     *
     * The stylesheet is found by what it holds, not where it is: the first
     * one that writes the colour.
     *
     * @throws ValidationException when the app writes its colours some other way.
     */
    public function handle(Preview $preview, User $owner, string $mode, string $token, string $after, string $revision): VisualEdit
    {
        $project = $preview->project;
        // Formatting since the owner's version changed nothing they see.
        $revision = $this->formatted->latest($project, $revision);

        if (! $preview->editable) {
            throw ValidationException::withMessages(['edit' => __('This preview cannot be edited.')]);
        }

        foreach ($this->repository->files($project, $revision) as $file) {
            if (! str_ends_with($file, '.css')) {
                continue;
            }

            $contents = (string) $this->repository->show($project, $revision, $file);
            $found = ThemeColors::find($contents, $mode, $token);

            if ($found === null) {
                continue;
            }

            try {
                $sha = $this->repository->commitFiles(
                    $project,
                    $revision,
                    [$file => (string) ThemeColors::write($contents, $mode, $token, $after)],
                    "Change the app's {$token} colour\n\nFor its {$mode} look, in {$file}.",
                    ['name' => $owner->name, 'email' => $owner->email],
                );
            } catch (RepositoryConflict $exception) {
                throw ValidationException::withMessages(['edit' => $exception->getMessage()]);
            }

            // The new commit rebuilds the editable preview (ProjectCommitted).
            return $project->visualEdits()->create([
                'experiment_id' => $project->experiment_id,
                'user_id' => $owner->id,
                'file' => $file,
                'line' => substr_count(substr($contents, 0, $found['offset']), "\n") + 1,
                'column' => 1,
                'tag' => ThemeColors::MODES[$mode],
                'device' => 'base',
                'changes' => [VisualEdit::THEME => ['mode' => $mode, 'token' => $token, 'before' => $found['value'], 'after' => $after]],
                'classes_before' => '',
                'classes_after' => '',
                'base_revision' => $revision,
                'commit_sha' => $sha,
            ]);
        }

        throw ValidationException::withMessages(['edit' => __('Your app keeps this colour some other way, so I can\'t change it here. Ask me to change it instead.')]);
    }
}
