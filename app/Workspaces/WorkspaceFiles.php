<?php

namespace App\Workspaces;

use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;

/**
 * Puts what a workspace needs besides the code into it, from our
 * database: the files its setup made the first time (such as `.env`) and
 * the project's notes. The database is the lasting copy; a workspace is
 * only a working copy.
 */
class WorkspaceFiles
{
    public function __construct(private WorkspaceManager $workspaces, private ProjectNotes $notes) {}

    /**
     * After setup: give the workspace the saved files, and save the ones
     * this workspace made that were not saved yet.
     */
    public function sync(Project $project, Workspace $workspace): void
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $saved = $project->workspaceFiles()->pluck('contents', 'path')->all();

        foreach (Config::array('builder.projects.workspace_files') as $path) {
            if (! is_string($path)) {
                continue;
            }

            if (isset($saved[$path])) {
                $driver->writeFile((string) $workspace->driver_id, $path, $saved[$path]);

                continue;
            }

            $contents = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);

            if (is_string($contents)) {
                $project->workspaceFiles()->firstOrCreate(['path' => $path], ['contents' => $contents]);
            }
        }
    }

    /**
     * Write the notes a change starts from into the workspace: the notes of
     * its line of work, as the changes it follows up on left them.
     */
    public function placeNotes(FeatureRequest $featureRequest, Workspace $workspace): void
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $files = $this->notes->files($featureRequest->project, $featureRequest->branch() ?? $featureRequest->project->branch());

        foreach (array_slice($featureRequest->lineage(), 0, -1) as $ancestor) {
            foreach ($ancestor->note_changes ?? [] as $path => $change) {
                $files[$path] = $change['after'];
            }
        }

        foreach (array_filter($files, is_string(...)) as $path => $contents) {
            ProjectNotes::assertPath($path);
            $driver->writeFile((string) $workspace->driver_id, ProjectNotes::directory().'/'.$path, $contents);
        }
    }
}
