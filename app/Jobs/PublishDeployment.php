<?php

namespace App\Jobs;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Workspace;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\Publishing\Exceptions\PublishingFailed;
use App\Publishing\PublishingHostManager;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PublishDeployment implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: installs, the full checks and
     * the push. Keep the queue connection's retry_after above this value.
     */
    public int $timeout = 3600;

    /**
     * A failed publish is not retried; the owner can publish again.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Deployment $deployment) {}

    /**
     * Run the verification setup and every check on the exact commit being
     * published, in a fresh workspace, and hand the commit to the host only
     * when all of them pass. Then the host's progress and the app's address
     * are checked (ConfirmDeployment). Publishing is the integration boundary, so the full checks
     * run however the commit was made (a kept change, a visual edit or a
     * notes edit).
     */
    public function handle(
        WorkspaceManager $workspaces,
        ProvisionWorkspace $provisionWorkspace,
        RunWorkspaceCommand $runWorkspaceCommand,
        DestroyWorkspace $destroyWorkspace,
        ProjectRepository $repository,
        PublishingHostManager $hosts,
    ): void {
        if ($this->deployment->fresh()?->status !== DeploymentStatus::Checking) {
            return;
        }

        $project = $this->deployment->project;
        $workspace = null;

        try {
            // Going back puts online a version that passed every check and
            // came online before, so it goes at once.
            $restores = $this->deployment->restores;

            if ($restores !== null) {
                $this->deployment->update(['checks' => $restores->checks]);
            } else {
                $workspace = $provisionWorkspace->handle($project->owner, (string) config('builder.verification.workspace_driver'));
                $driver = $workspaces->driver($workspace->driver);
                $repository->withCheckout($project, $this->deployment->commit_sha, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

                if (! $this->passes($runWorkspaceCommand, $workspace)) {
                    $this->finish(DeploymentStatus::Failed, __('A check did not pass, so I did not publish. Your app online has not changed.'));

                    return;
                }
            }

            $this->deployment->update(['status' => DeploymentStatus::Pushing, 'release_sha' => $this->releaseCommit($repository)]);

            $hosts->driver($this->deployment->host ?? $project->publishingHost())->release($project, $this->deployment);
            $project->refresh();

            // The host takes it from here; it is online only once the host
            // says so (when it reports at all) and its address answers.
            if ($project->live_url === null) {
                $this->deployment->update(['pushed_at' => now()]);
                $this->finish(DeploymentStatus::Sent);

                return;
            }

            $this->deployment->update(['status' => DeploymentStatus::Confirming, 'pushed_at' => now()]);

            ConfirmDeployment::dispatch($this->deployment)->delay((int) config('builder.publishing.confirm.settle_seconds'));
        } catch (RepositoryConflict|PublishingFailed $exception) {
            $this->finish(DeploymentStatus::Failed, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            $this->finish(DeploymentStatus::Failed, ProjectRepository::withoutCredentials($exception->getMessage(), (string) $project->deploy_remote));
        } finally {
            if ($workspace !== null) {
                rescue(fn () => $destroyWorkspace->handle($workspace));
            }
        }
    }

    /**
     * Get the commit to send when the host has a commit the checked one does
     * not build on: after going back, the host has the earlier files on top
     * of the newer ones. The commit holds the checked files on top of both,
     * so the host takes it without forcing. Null when the checked commit
     * itself builds on what the host has.
     */
    protected function releaseCommit(ProjectRepository $repository): ?string
    {
        $project = $this->deployment->project;
        $sent = $project->deployments()
            ->whereKeyNot($this->deployment->id)
            ->where('host', $this->deployment->host)
            ->where('branch', $this->deployment->branch)
            ->whereNotNull('pushed_at')
            // One publish runs at a time, so the newest sent is the last.
            ->latest('id')
            ->first()
            ?->released();

        if ($sent === null || $repository->isAncestor($project, $sent, $this->deployment->commit_sha)) {
            return null;
        }

        $restores = $this->deployment->restores;
        $message = $restores === null
            ? 'Publish the newest version'
            : 'Go back to the version published on '.$restores->finished_at?->toDateString();

        return $repository->releaseCommit(
            $project,
            $this->deployment->commit_sha,
            [$this->deployment->commit_sha, $sent],
            $message,
            null,
            "refs/releases/{$this->deployment->id}",
        );
    }

    /**
     * Record an unexpected failure (for example a worker timeout).
     */
    public function failed(?Throwable $exception): void
    {
        $this->finish(DeploymentStatus::Failed, __('Publishing stopped unexpectedly. Your app online may not have changed.'));
    }

    /**
     * Run the setup steps, stopping at the first failure, then every check,
     * and record each result on the deployment as it finishes, so the owner
     * sees which check runs.
     */
    protected function passes(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace): bool
    {
        /** @var list<array{name: string, command: list<string>, timeout: int}> $setup */
        $setup = config('builder.verification.setup', []);

        /** @var list<array{name: string, command: list<string>, timeout: int}> $checks */
        $checks = config('builder.verification.checks', []);

        $results = [];
        $passed = true;

        foreach ($setup as $step) {
            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $results[] = ['name' => $step['name'], 'passed' => $command->exit_code === 0 && ! $command->timed_out];
            $this->deployment->update(['checks' => $results]);

            if (! end($results)['passed']) {
                return false;
            }
        }

        foreach ($checks as $step) {
            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $results[] = ['name' => $step['name'], 'passed' => $command->exit_code === 0 && ! $command->timed_out];
            $passed = $passed && end($results)['passed'];
            $this->deployment->update(['checks' => $results]);
        }

        return $passed;
    }

    /**
     * Finish the deployment with a status and, when it failed, why.
     */
    protected function finish(DeploymentStatus $status, ?string $error = null): void
    {
        $this->deployment->update(['status' => $status, 'error' => $error, 'finished_at' => now()]);
    }
}
