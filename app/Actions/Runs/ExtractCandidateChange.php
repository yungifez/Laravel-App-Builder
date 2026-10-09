<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Context\ProjectNotes;
use App\Models\Workspace;
use App\Runs\Exceptions\ConstructionFailed;
use App\Workspaces\WorkspaceManager;

class ExtractCandidateChange
{
    /**
     * Where the diff is written inside the workspace, outside the project's files.
     */
    protected const PATCH_PATH = '.git/candidate.patch';

    /**
     * Where a note's baseline copy is read from.
     */
    protected const BEFORE_PATH = '.git/note-before';

    public function __construct(
        private WorkspaceManager $workspaces,
        private RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    /**
     * Read the run's change back from the workspace as a patch against the
     * baseline, whatever the driver claims it did. The notes are not part
     * of it: they never go into the app's repository (see notes()).
     *
     * The baseline is the commit recorded when the workspace was prepared,
     * not the workspace's HEAD: an agent that commits its own work must not
     * hide that work from the change.
     *
     * @throws ConstructionFailed
     */
    public function handle(Workspace $workspace): string
    {
        $baseline = $this->baseline($workspace);

        $this->run($workspace, ['git', 'add', '--all']);
        $this->run($workspace, ['git', 'diff', '--cached', '--binary', '--no-color', '--no-ext-diff', '--output='.self::PATCH_PATH, $baseline, '--', '.', ':(exclude)'.ProjectNotes::directory()]);

        return $this->workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, self::PATCH_PATH);
    }

    /**
     * Read what the run did to the notes: each changed file as it was in
     * the baseline and as the run left it (null when it does not exist).
     *
     * @return array<string, array{before: string|null, after: string|null}>
     *
     * @throws ConstructionFailed
     */
    public function notes(Workspace $workspace): array
    {
        $directory = ProjectNotes::directory();
        $baseline = $this->baseline($workspace);
        $this->run($workspace, ['git', 'add', '--all']);
        $changed = $this->run($workspace, ['git', 'diff', '--cached', '--name-only', '--no-renames', '-z', $baseline, '--', $directory]);
        $driver = $this->workspaces->driver($workspace->driver);
        $changes = [];

        foreach (array_filter(explode("\0", $changed)) as $path) {
            // Command output is cut short, so the old copy goes through a file.
            $before = $this->runWorkspaceCommand->handle($workspace, ['sh', '-c', 'git show "$1:$2" > "$3"', 'sh', $baseline, $path, self::BEFORE_PATH], 60);
            $after = rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);

            $changes[substr($path, strlen($directory) + 1)] = [
                'before' => $before->exit_code === 0 ? $driver->readFile((string) $workspace->driver_id, self::BEFORE_PATH) : null,
                'after' => is_string($after) ? $after : null,
            ];
        }

        return $changes;
    }

    /**
     * Get the commit the change is measured against.
     *
     * @throws ConstructionFailed
     */
    public function baseline(Workspace $workspace): string
    {
        return $workspace->baseline_commit ?? throw new ConstructionFailed(__('The change could not be read from the workspace. Its starting point is unknown.'));
    }

    /**
     * Run a git command and return its output.
     *
     * @param  list<string>  $command
     *
     * @throws ConstructionFailed
     */
    protected function run(Workspace $workspace, array $command): string
    {
        $result = $this->runWorkspaceCommand->handle($workspace, $command, 120);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw new ConstructionFailed(__('The change could not be read from the workspace. :reason', ['reason' => trim($result->error_output)]));
        }

        return $result->output;
    }
}
