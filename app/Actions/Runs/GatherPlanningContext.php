<?php

namespace App\Actions\Runs;

use App\Actions\Context\ReadProjectContext;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\PlanningContext;
use App\Workspaces\WorkspaceManager;

class GatherPlanningContext
{
    public function __construct(
        private WorkspaceManager $workspaces,
        private RunWorkspaceCommand $runWorkspaceCommand,
        private ReadProjectContext $readProjectContext,
    ) {}

    /**
     * Build the planner's bounded view of the project: the request, what it
     * follows up on, the file list, a few key files and the application's
     * own notes in `.builder/`.
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

        return new PlanningContext(
            request: $featureRequest->instructions(),
            files: array_slice($files, 0, $limit),
            contents: $this->contents($workspace, array_values(array_intersect(array_unique($contextFiles), $files))),
            parentRequest: $parent?->prompt,
            parentSummary: $parent?->summary,
            targetStep: $targetStep,
            projectContext: $this->readProjectContext->handle($workspace, $files),
        );
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
