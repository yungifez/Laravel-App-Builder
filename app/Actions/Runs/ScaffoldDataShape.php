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
     * work alone. The app's route file is the one file added to, and
     * only where a route goes; what was not added is noted with why. A
     * format's rule or cast the app already has is kept and noted.
     *
     * @return array{files: list<string>, notes: list<string>} The paths written, and what was left to the agent
     */
    public function handle(Workspace $workspace, Plan $plan): array
    {
        if ($plan->dataShape === []) {
            return ['files' => [], 'notes' => []];
        }

        $existing = array_values(array_filter(explode("\0", $this->runWorkspaceCommand->handle(
            $workspace,
            ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', '--', 'app', 'database', 'routes'],
            120,
        )->output)));

        // Follow the app's own way of naming fillable fields.
        $attributes = $this->runWorkspaceCommand->handle(
            $workspace,
            ['git', 'grep', '--quiet', '-F', 'Eloquent\\Attributes\\Fillable', '--', 'app/Models'],
            120,
        )->exit_code === 0;

        $driver = $this->workspaces->driver($workspace->driver);
        $routes = [];

        foreach ($existing as $path) {
            if (str_starts_with($path, 'routes/') && str_ends_with($path, '.php')) {
                $routes[$path] = $driver->readFile((string) $workspace->driver_id, $path);
            }
        }

        // The app's own rules and casts of the same name are compared, not
        // written over.
        $contents = [];

        foreach (array_intersect($this->scaffold->supportPaths($plan->dataShape, $existing), $existing) as $path) {
            $contents[$path] = $driver->readFile((string) $workspace->driver_id, $path);
        }

        $reached = $this->scaffold->routes($plan->dataShape, $existing, $routes);
        $composer = json_decode(rescue(fn () => $driver->readFile((string) $workspace->driver_id, 'composer.json'), '', false), true);
        $packages = array_keys(is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : []);
        $support = $this->scaffold->support($plan->dataShape, $existing, $contents, array_map(strval(...), $packages));
        $files = [...$this->scaffold->files($plan->dataShape, $existing, Date::now(), $attributes), ...$reached['files'], ...$support['files']];

        foreach ($files as $path => $contents) {
            $driver->writeFile((string) $workspace->driver_id, $path, $contents);
        }

        return ['files' => array_keys($files), 'notes' => [...$reached['notes'], ...$support['notes']]];
    }
}
