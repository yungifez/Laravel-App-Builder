<?php

namespace App\Actions\Projects;

use App\Models\Experiment;
use App\Models\Project;
use App\Projects\ProjectRepository;
use Illuminate\Support\Str;

class PackProject
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Pack the app's code, as the owner keeps it, into a zip file. The code
     * is theirs, so a developer can take it over without asking us. Only
     * the files go: the history, and the notes, which are ours (§19), stay.
     *
     * @return string|null The path of a temporary zip file, which the caller
     *                     deletes, or null when the app has no code yet.
     */
    public function handle(Project $project): ?string
    {
        $head = $this->repository->exists($project) ? $this->repository->head($project, Experiment::mainBranch()) : '';

        if ($head === '') {
            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'app-download-');

        $this->repository->git($project, ['archive', '--format=zip', '--prefix='.$this->folder($project).'/', '--output='.$path, $head], timeout: 300);

        return $path;
    }

    /**
     * The folder the zip unpacks into, named after the app.
     */
    public function folder(Project $project): string
    {
        return Str::slug($project->name) ?: 'app';
    }
}
