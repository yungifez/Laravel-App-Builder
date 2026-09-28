<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\WorkspaceStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\RunLease;
use App\Workspaces\Drivers\CopyExclusions;
use App\Workspaces\WorkspaceFiles;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareRunWorkspace
{
    public function __construct(
        private WorkspaceManager $workspaces,
        private ProvisionWorkspace $provisionWorkspace,
        private RunWorkspaceCommand $runWorkspaceCommand,
        private DestroyWorkspace $destroyWorkspace,
        private ProjectRepository $repository,
        private WorkspaceFiles $workspaceFiles,
    ) {}

    /**
     * Get the run's workspace, preparing one if it has none: copy the project
     * in as of the request's base revision, apply the changes the request
     * follows up on, add the notes, commit that as the baseline the run's
     * change is measured against, run the setup, then add the saved
     * workspace files.
     *
     * @throws ConstructionFailed when the project cannot be prepared.
     */
    public function handle(Run $run, RunLease $lease): Workspace
    {
        $existing = $run->workspace;

        if ($existing !== null && $existing->status === WorkspaceStatus::Ready) {
            return $existing;
        }

        $featureRequest = $run->featureRequest;
        $project = $featureRequest->project;
        $workspace = $this->provisionWorkspace->handle($project->owner, (string) config('builder.construction.workspace_driver'));

        try {
            $driver = $this->workspaces->driver($workspace->driver);
            $this->repository->withCheckout($project, $featureRequest->base_revision, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

            foreach (array_slice($featureRequest->lineage(), 0, -1) as $position => $ancestor) {
                $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $ancestor->patch);

                $this->run($workspace, ['git', 'apply', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), $patch], __('Change #:id no longer applies to the project.', ['id' => $ancestor->id]));
            }

            $this->run($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], __('The workspace could not be prepared.'));
            $this->workspaceFiles->placeNotes($featureRequest, $workspace);

            // The agent can read this history, so it names only the owner.
            $identity = ['-c', "user.name={$project->owner->name}", '-c', "user.email={$project->owner->email}", '-c', 'commit.gpgsign=false'];
            $this->run($workspace, ['git', 'init', '--quiet'], __('The workspace could not be prepared.'));
            $this->run($workspace, ['git', 'add', '--all'], __('The workspace could not be prepared.'));
            $this->run($workspace, ['git', ...$identity, 'commit', '--quiet', '--allow-empty', '--no-verify', '-m', 'Baseline'], __('The workspace could not be prepared.'));
            $workspace->update(['baseline_commit' => trim($this->run($workspace, ['git', 'rev-parse', 'HEAD'], __('The workspace could not be prepared.')))]);
            // Inside .git, so the pictures are there to look at but never part of the change.
            $this->workspaceFiles->placeImages($featureRequest, $workspace);

            /** @var list<array{name: string, command: list<string>, timeout: int}> $setup */
            $setup = config('builder.construction.setup', []);

            foreach ($setup as $step) {
                $this->run($workspace, $step['command'], __('The setup step ":name" failed.', ['name' => $step['name']]), $step['timeout']);
            }

            $this->workspaceFiles->sync($project, $workspace);

            DB::transaction(function () use ($run, $lease, $workspace) {
                $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

                $lease->assertHeldOn($locked);

                $locked->workspace_id = $workspace->id;
                $locked->lease_expires_at = now()->addSeconds((int) config('builder.construction.lease_seconds'));
                $locked->save();
                $locked->recordEvent('workspace_ready', ['workspace_id' => $workspace->id]);

                $run->setRawAttributes($locked->getAttributes(), sync: true);
            });
        } catch (Throwable $exception) {
            rescue(fn () => $this->destroyWorkspace->handle($workspace));

            throw $exception;
        }

        return $workspace;
    }

    /**
     * Run a preparation command and return its output, or stop with the
     * given reason if it fails.
     *
     * @param  list<string>  $command
     *
     * @throws ConstructionFailed
     */
    protected function run(Workspace $workspace, array $command, string $reason, int $timeoutSeconds = 120): string
    {
        $result = $this->runWorkspaceCommand->handle($workspace, $command, $timeoutSeconds);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw new ConstructionFailed(trim($reason.' '.trim($result->error_output)));
        }

        return $result->output;
    }
}
