<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Context\Exceptions\InvalidContextFile;
use App\Context\ProjectContext;
use App\Enums\NotesDraftStatus;
use App\Models\Project;
use App\Models\User;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;

class KeepNotesDraft
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Write the drafted notes into the app, as the owner confirmed them:
     * what it is for, and one file per area. Nothing the model drafted is
     * used until this point.
     *
     * @throws ValidationException when there is no draft, the app already
     *                             has notes, or a drafted area is not valid.
     */
    public function handle(Project $project, User $owner): string
    {
        $draft = $project->notes_draft;

        if ($project->notes_draft_status !== NotesDraftStatus::Ready || $draft === null || ! $this->repository->exists($project)) {
            throw ValidationException::withMessages(['draft' => __('There is no draft to keep. Reload the page.')]);
        }

        $head = $this->repository->head($project);

        if ($this->repository->show($project, $head, ProjectContext::PROJECT_FILE) !== null) {
            throw ValidationException::withMessages(['draft' => __('Your app already has notes, so I did not replace them.')]);
        }

        $files = [ProjectContext::PROJECT_FILE => "# Project\n\n".wordwrap($draft['purpose'], 78)."\n"];

        foreach ($draft['areas'] as $area) {
            $file = ProjectContext::CAPABILITIES_DIRECTORY."/{$area['key']}.md";
            $files[$file] = self::capabilityFile($area);

            try {
                Capability::fromMarkdown($file, $files[$file]);
            } catch (InvalidContextFile $exception) {
                report($exception);

                throw ValidationException::withMessages(['draft' => __('Part of the draft could not be used. Discard it and write the notes yourself.')]);
            }
        }

        try {
            $sha = $this->repository->commitFiles(
                $project,
                $head,
                $files,
                "Describe the app from its code\n\nDrafted from the imported code and confirmed by the owner.\nBuilder-Notes-Edit: yes",
                ['name' => $owner->name, 'email' => $owner->email],
            );
        } catch (RepositoryConflict $exception) {
            throw ValidationException::withMessages(['draft' => $exception->getMessage()]);
        }

        $project->update(['notes_draft_status' => null, 'notes_draft' => null, 'notes_draft_error' => null]);

        return $sha;
    }

    /**
     * Write one drafted area as a capability file.
     *
     * @param  array{key: string, name: string, summary: string, paths: list<string>, behaviors: list<array{key: string, name: string}>, rules: list<string>}  $area
     */
    public static function capabilityFile(array $area): string
    {
        $frontmatter = array_filter([
            'capability' => $area['key'],
            'summary' => $area['summary'] === '' ? null : $area['summary'],
            'paths' => $area['paths'],
            'behaviors' => $area['behaviors'],
        ], fn (mixed $value) => $value !== null && $value !== []);

        $markdown = "---\n".Yaml::dump($frontmatter, 4, 4)."---\n\n# {$area['name']}\n";

        if ($area['rules'] !== []) {
            $markdown .= "\n## Rules\n\n".UpdateProjectNotes::bullets(implode("\n", $area['rules']))."\n";
        }

        return $markdown;
    }
}
