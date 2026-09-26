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
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\DB;
use Throwable;

class PrepareRunWorkspace
{
    /**
     * Git identity for the baseline commit, so no host configuration is needed.
     *
     * @var list<string>
     */
    protected const GIT_IDENTITY = ['-c', 'user.name=Builder', '-c', 'user.email=builder@localhost', '-c', 'commit.gpgsign=false'];

    public function __construct(
        private WorkspaceManager $workspaces,
        private ProvisionWorkspace $provisionWorkspace,
        private RunWorkspaceCommand $runWorkspaceCommand,
        private DestroyWorkspace $destroyWorkspace,
        private ProjectRepository $repository,
    ) {}

    /**
     * Get the run's workspace, preparing one if it has none: copy the project
     * in as of the request's base revision, apply the changes the request follows up on, commit that as the
     * baseline the run's change is measured against, then run the setup.
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

                $this->run($workspace, ['git', 'apply', '--whitespace=nowarn', $patch], __('Change #:id no longer applies to the project.', ['id' => $ancestor->id]));
            }

            $this->run($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], __('The workspace could not be prepared.'));
            $this->run($workspace, ['git', 'init', '--quiet'], __('The workspace could not be prepared.'));
            $this->run($workspace, ['git', 'add', '--all'], __('The workspace could not be prepared.'));
            $this->run($workspace, ['git', ...self::GIT_IDENTITY, 'commit', '--quiet', '--allow-empty', '--no-verify', '-m', 'Baseline'], __('The workspace could not be prepared.'));

            /** @var list<array{name: string, command: list<string>, timeout: int}> $setup */
            $setup = config('builder.construction.setup', []);

            foreach ($setup as $step) {
                $this->run($workspace, $step['command'], __('The setup step ":name" failed.', ['name' => $step['name']]), $step['timeout']);
            }

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
     * Run a preparation command and stop with the given reason if it fails.
     *
     * @param  list<string>  $command
     *
     * @throws ConstructionFailed
     */
    protected function run(Workspace $workspace, array $command, string $reason, int $timeoutSeconds = 120): void
    {
        $result = $this->runWorkspaceCommand->handle($workspace, $command, $timeoutSeconds);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw new ConstructionFailed(trim($reason.' '.trim($result->error_output)));
        }
    }
}
