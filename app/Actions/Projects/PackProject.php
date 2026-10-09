<?php

namespace App\Actions\Projects;

use App\Models\Experiment;
use App\Models\Project;
use App\Projects\ProjectRepository;
use App\Workspaces\Drivers\CopyExclusions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PackProject
{
    public function __construct(private ProjectRepository $repository) {}

    /**
     * Pack the app's code, as the owner keeps it, into a zip file. The code
     * is theirs, so a developer can take it over without asking us. Only
     * the files go: the history, and the notes, which are ours (§19), stay.
     *
     * A revision packs the code as it was then, so a developer asked to
     * look at it reads the same code however the app moves on. Patches of
     * changes not kept yet go on top, in order, so the code is the same as
     * the one a follow-up is written and checked on.
     *
     * @param  list<string>  $patches
     * @return string|null The path of a temporary zip file, which the caller
     *                     deletes, or null when the app has no code yet.
     */
    public function handle(Project $project, ?string $revision = null, array $patches = []): ?string
    {
        $head = $this->repository->exists($project) ? ($revision ?? $this->repository->head($project, Experiment::mainBranch())) : '';

        if ($head === '') {
            return null;
        }

        if ($patches !== []) {
            $head = $this->applied($project, $head, $patches);
        }

        $path = tempnam(sys_get_temp_dir(), 'app-download-');

        $this->repository->git($project, ['archive', '--format=zip', '--prefix='.$this->folder($project).'/', '--output='.$path, $head], timeout: 300);

        return $path;
    }

    /**
     * Apply the patches to the revision in an index of its own, so the
     * repository and its branches stay as they are, and get the tree.
     *
     * @param  list<string>  $patches
     */
    protected function applied(Project $project, string $revision, array $patches): string
    {
        $index = (string) tempnam(sys_get_temp_dir(), 'app-index-');
        $patchFile = (string) tempnam(sys_get_temp_dir(), 'app-patch-');
        $env = ['GIT_INDEX_FILE' => $index];

        try {
            $this->repository->git($project, ['read-tree', $revision], env: $env);

            foreach ($patches as $patch) {
                File::put($patchFile, $patch);
                $this->repository->git($project, ['apply', '--cached', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), $patchFile], env: $env);
            }

            return trim($this->repository->git($project, ['write-tree'], env: $env)->output());
        } finally {
            File::delete([$index, $patchFile]);
        }
    }

    /**
     * The folder the zip unpacks into, named after the app.
     */
    public function folder(Project $project): string
    {
        return Str::slug($project->name) ?: 'app';
    }
}
