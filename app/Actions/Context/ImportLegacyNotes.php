<?php

namespace App\Actions\Context;

use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Projects\ProjectRepository;

class ImportLegacyNotes
{
    public function __construct(private ProjectNotes $notes, private ProjectRepository $repository) {}

    /**
     * Move the legacy notes directory of one branch out of the repository
     * and into our database. Notes already in the database win.
     *
     * @return int How many files moved
     */
    public function fromBranch(Project $project, string $branch): int
    {
        $head = $this->repository->head($project, $branch);
        $prefix = ProjectContext::LEGACY_DIRECTORY.'/';
        $paths = array_values(array_filter($this->repository->files($project, $head), fn (string $path) => str_starts_with($path, $prefix)));

        if ($paths === []) {
            return 0;
        }

        if ($this->notes->files($project, $branch) === []) {
            $files = [];

            foreach ($paths as $path) {
                $files[substr($path, strlen($prefix))] = (string) $this->repository->show($project, $head, $path);
            }

            $this->notes->put($project, $branch, $files);
        }

        $this->repository->commitFiles($project, $head, [ProjectContext::LEGACY_DIRECTORY => null], 'Remove the notes folder', null, $branch);

        return count($paths);
    }
}
