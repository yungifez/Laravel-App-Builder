<?php

namespace App\Actions\Runs;

use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\SelectAreas;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Context\AreaNames;
use App\Enums\FeatureRequestStatus;
use App\Models\Run;
use App\Models\Workspace;
use App\Projects\Frontend;
use App\Runs\PlanningContext;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Str;

class GatherPlanningContext
{
    public function __construct(
        private WorkspaceManager $workspaces,
        private RunWorkspaceCommand $runWorkspaceCommand,
        private ReadProjectContext $readProjectContext,
        private SelectAreas $selectAreas,
    ) {}

    /**
     * Build the planner's bounded view of the project: the request, what it
     * follows up on, the file list, a few key files, the application's own
     * notes (the workspace's copy), and what the owner already answered.
     */
    public function handle(Run $run, Workspace $workspace): PlanningContext
    {
        $featureRequest = $run->featureRequest;
        $parent = $featureRequest->parent;
        $targetStep = $parent !== null && $featureRequest->target_step !== null ? $parent->step($featureRequest->target_step) : null;

        $listing = $this->runWorkspaceCommand->handle($workspace, ['git', 'ls-files', '--cached', '--others', '--exclude-standard'], 60);
        $files = array_values(array_filter(explode("\n", $listing->output), fn (string $line) => $line !== '' && ! str_starts_with($line, '…')));
        $limit = (int) config('builder.construction.planning.max_files');

        /** @var list<string> $contextFiles */
        $contextFiles = config('builder.construction.planning.context_files', []);

        if ($targetStep !== null) {
            $contextFiles[] = $targetStep['file'];
        }

        $projectContext = $this->readProjectContext->handle($workspace, $files, $featureRequest->project);
        $areas = $this->selectAreas->handle($projectContext, $featureRequest);

        // In a large app the list is cut short, so the code of the areas the
        // change is about comes first and is never cut.
        $ours = array_filter($files, fn (string $path) => array_intersect($projectContext->claiming($path), array_keys($areas)) !== []);
        $files = [...array_values($ours), ...array_values(array_diff($files, $ours))];

        // Recorded, so the change can say later which way it was built.
        $keepOldWorking = $featureRequest->project->keepsOldWorking();
        $run->recordEvent('compatibility', ['keep_old_working' => $keepOldWorking, 'chosen_by_owner' => $featureRequest->project->keep_old_working !== null]);

        return new PlanningContext(
            request: $featureRequest->instructions(),
            files: array_slice($files, 0, $limit),
            contents: $this->contents($workspace, array_values(array_intersect(array_unique($contextFiles), $files))),
            parentRequest: $parent?->prompt,
            parentSummary: $parent?->summary,
            targetStep: $targetStep,
            projectContext: $projectContext,
            answers: $run->answers ?? [],
            mayAsk: count($run->answers ?? []) < $run->question_limit,
            parentAnswered: $parent?->status === FeatureRequestStatus::Answered,
            keepOldWorking: $keepOldWorking,
            services: $featureRequest->project->connectedServices(),
            routes: in_array('artisan', $files, true) ? $this->routes($workspace) : [],
            frontend: $this->frontend($workspace, $files),
            areas: $areas,
            names: $this->names($workspace, array_values($ours)),
        );
    }

    /**
     * Read the names the code of the change's areas already uses: the data
     * its controllers pass to their pages and its models' relations, so the
     * plan names them as the app does, such as "can.deleteTeam" rather than
     * a new "canDelete". Up to "max_name_files" files and "max_names" lines.
     *
     * @param  list<string>  $files  The files of the change's areas
     * @return list<string>
     */
    protected function names(Workspace $workspace, array $files): array
    {
        $code = array_values(array_filter($files, fn (string $path) => preg_match('#^app/(Http/Controllers|Models)/.+\.php$#', $path) === 1));
        sort($code);
        $lines = [];

        foreach ($this->contents($workspace, array_slice($code, 0, (int) config('builder.construction.planning.max_name_files'))) as $path => $contents) {
            array_push($lines, ...AreaNames::pages($path, $contents), ...AreaNames::relations($path, $contents));
        }

        return array_slice($lines, 0, (int) config('builder.construction.planning.max_names'));
    }

    /**
     * Name what the app's screens are made with, from its manifests, so the
     * planner builds new screens the way the app builds its others.
     *
     * @param  list<string>  $files
     */
    protected function frontend(Workspace $workspace, array $files): Frontend
    {
        $manifests = $this->contents($workspace, array_values(array_intersect(['composer.json', 'package.json'], $files)));

        return Frontend::detect($manifests['composer.json'] ?? null, $manifests['package.json'] ?? null);
    }

    /**
     * The app's addresses and the code that handles each, as Laravel lists
     * them, so the planner can name the right files instead of the coding
     * agent searching for them. Left out when the app cannot list them.
     *
     * @return list<string>
     */
    public function routes(Workspace $workspace): array
    {
        $listing = rescue(fn () => $this->runWorkspaceCommand->handle($workspace, ['php', 'artisan', 'route:list', '--json', '--except-vendor', '--no-ansi'], 60), null, report: false);
        $routes = $listing?->exit_code === 0 ? json_decode($listing->output, true) : null;

        if (! is_array($routes)) {
            return [];
        }

        $lines = [];

        foreach ($routes as $route) {
            if (! is_array($route) || ! is_string($route['uri'] ?? null)) {
                continue;
            }

            $method = Str::before((string) ($route['method'] ?? ''), '|HEAD');
            $action = Str::after((string) ($route['action'] ?? ''), 'App\\Http\\Controllers\\');
            $name = is_string($route['name'] ?? null) ? " ({$route['name']})" : '';

            $lines[] = "{$method} /".ltrim($route['uri'], '/')." → {$action}{$name}";
        }

        return array_slice($lines, 0, (int) config('builder.construction.planning.max_routes'));
    }

    /**
     * Read the given project files, skipping any that are too large.
     *
     * @param  list<string>  $paths
     * @return array<string, string>
     */
    protected function contents(Workspace $workspace, array $paths): array
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $limit = (int) config('builder.construction.limits.read_bytes');
        $contents = [];

        foreach ($paths as $path) {
            $text = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);

            if (is_string($text) && strlen($text) <= $limit) {
                $contents[$path] = $text;
            }
        }

        return $contents;
    }
}
