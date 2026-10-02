<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Workspace;
use App\Runs\Plan;
use App\Scaffolding\Scaffold;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Date;

class ScaffoldDataShape
{
    public function __construct(
        private RunWorkspaceCommand $runWorkspaceCommand,
        private WorkspaceManager $workspaces,
        private Scaffold $scaffold,
    ) {}

    /**
     * Write the files the plan's data shape fixes into the workspace, before
     * the coding agent starts (§9). Files the app already has are never
     * written over, so a repair pass or a second try leaves the agent's
     * work alone.
     *
     * @return list<string> The paths written
     */
    public function handle(Workspace $workspace, Plan $plan): array
    {
        if ($plan->dataShape === []) {
            return [];
        }

        $existing = array_values(array_filter(explode("\0", $this->runWorkspaceCommand->handle(
            $workspace,
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', 'app', 'database'],
            120,
        )->output)));

        // Follow the app's own way of naming fillable fields.
        $attributes = $this->runWorkspaceCommand->handle(
            $workspace,
            ['git', 'grep', '--quiet', '-F', 'Eloquent\\Attributes\\Fillable', '--', 'app/Models'],
            120,
        )->exit_code === 0;

        $files = $this->scaffold->files($plan->dataShape, $existing, Date::now(), $attributes);
        $driver = $this->workspaces->driver($workspace->driver);

        foreach ($files as $path => $contents) {
            $driver->writeFile((string) $workspace->driver_id, $path, $contents);
        }

        return array_keys($files);
    }
}
