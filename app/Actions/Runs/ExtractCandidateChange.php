<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Workspace;
use App\Runs\Exceptions\ConstructionFailed;
use App\Workspaces\WorkspaceManager;

class ExtractCandidateChange
{
    /**
     * Where the diff is written inside the workspace, outside the project's files.
     */
    protected const PATCH_PATH = '.git/builder-candidate.patch';

    public function __construct(
        private WorkspaceManager $workspaces,
        private RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    /**
     * Read the run's change back from the workspace as a patch against the
     * baseline, whatever the driver claims it did.
     *
     * @throws ConstructionFailed
     */
    public function handle(Workspace $workspace): string
    {
        foreach ([
            ['git', 'add', '--all'],
            ['git', 'diff', '--cached', '--binary', '--no-color', '--no-ext-diff', '--output='.self::PATCH_PATH],
        ] as $command) {
            $result = $this->runWorkspaceCommand->handle($workspace, $command, 120);

            if ($result->exit_code !== 0 || $result->timed_out) {
                throw new ConstructionFailed(__('The change could not be read from the workspace. :reason', ['reason' => trim($result->error_output)]));
            }
        }

        return $this->workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, self::PATCH_PATH);
    }
}
