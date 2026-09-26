<?php

namespace App\Actions\Projects;

use App\Actions\Context\UpdateProjectNotes;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartProjectFromTemplate
{
    public function __construct(
        private CreateProject $createProject,
        private UpdateProjectNotes $updateProjectNotes,
        private ProjectRepository $repository,
    ) {}

    /**
     * Start a new app from the configured template and write the owner's
     * one answer, what the app is for, into its notes.
     *
     * @throws ValidationException when no template is configured or it
     *                             cannot be imported.
     */
    public function handle(User $owner, string $name, string $purpose): Project
    {
        $template = config('builder.projects.template');

        if (! is_string($template) || $template === '') {
            throw ValidationException::withMessages(['name' => __('Starting a new app is not set up here.')]);
        }

        return DB::transaction(function () use ($owner, $name, $purpose, $template) {
            $project = $this->createProject->handle($owner, $name, $template, draftNotes: false);

            $this->updateProjectNotes->handle($project, $owner, 'introduction', $purpose, $this->repository->head($project));

            return $project;
        });
    }
}
