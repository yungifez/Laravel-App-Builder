<?php

namespace App\Actions\Workspaces;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Workspaces\WorkspaceManager;
use Closure;
use RuntimeException;

class LoadProjectIntoWorkspace
{
    public function __construct(
        private WorkspaceManager $workspaces,
        private RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    /**
     * Copy the project into the workspace and apply the given changes in
     * order, stopping at the first change that does not apply.
     *
     * @param  list<FeatureRequest>  $changes
     * @param  (Closure(FeatureRequest, WorkspaceCommand): mixed)|null  $applied  Called with each attempted change and its `git apply` command; its return value is ignored.
     * @return FeatureRequest|null The change that did not apply, or null when every change applied.
     *
     * @throws RuntimeException when the workspace cannot be prepared.
     */
    public function handle(Workspace $workspace, Project $project, array $changes, ?Closure $applied = null): ?FeatureRequest
    {
        $driver = $this->workspaces->driver($workspace->driver);
        $driver->copyDirectory((string) $workspace->driver_id, $project->source_path);

        foreach ($changes as $position => $change) {
            $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
            $driver->writeFile((string) $workspace->driver_id, $patch, (string) $change->patch);

            $command = $this->runWorkspaceCommand->handle($workspace, ['git', 'apply', '--whitespace=nowarn', $patch], 120);

            if ($applied !== null) {
                $applied($change, $command);
            }

            if ($command->exit_code !== 0 || $command->timed_out) {
                return $change;
            }
        }

        $cleanup = $this->runWorkspaceCommand->handle($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30);

        if ($cleanup->exit_code !== 0 || $cleanup->timed_out) {
            throw new RuntimeException(__('The workspace could not be prepared.'));
        }

        return null;
    }
}
