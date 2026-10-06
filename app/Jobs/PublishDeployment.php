<?php

namespace App\Jobs;

use App\Actions\Workspaces\CheckStepNeeds;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Projects\Exceptions\RepositoryConflict;
use App\Projects\ProjectRepository;
use App\Publishing\Exceptions\PublishingFailed;
use App\Publishing\PublishingHostManager;
use App\Support\Secrets;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
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
        $sent = false;

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
            $host = $hosts->driver($this->deployment->host ?? $project->publishingHost());

            // A release that changes how information is stored may lose
            // some of it, and going back does not undo that, so the host
            // saves a copy first.
            if ($this->changesStorage($repository)) {
                $this->deployment->update(['backup_id' => $host->backup($project, $this->deployment)]);
            }

            $host->release($project, $this->deployment);
            $sent = true;
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
            $this->finish(DeploymentStatus::Failed, $exception->getMessage(), $exception instanceof PublishingFailed && $exception->settings ? 'settings' : null, $exception->getPrevious());
        } catch (Throwable $exception) {
            report($exception);

            $this->finish(DeploymentStatus::Failed, $sent
                ? __('This is our fault: something went wrong on our side after your new version was sent to your hosting. Try again.')
                : __('This is our fault: publishing stopped on our side. Your app online has not changed. Try again.'), 'ours', $exception);
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
     * Determine if the release changes the app's migrations since the last
     * version sent. The first release has no information to keep yet.
     */
    protected function changesStorage(ProjectRepository $repository): bool
    {
        $project = $this->deployment->project;
        $sent = $project->deployments()
            ->whereKeyNot($this->deployment->id)
            ->whereNotNull('pushed_at')
            ->latest('id')
            ->first();

        if ($sent === null) {
            return false;
        }

        $changed = array_keys($repository->changedFiles($project, $sent->commit_sha, $this->deployment->commit_sha));

        return collect($changed)->contains(fn (string $file) => str_starts_with($file, 'database/migrations/'));
    }

    /**
     * Record an unexpected failure (for example a worker timeout).
     */
    public function failed(?Throwable $exception): void
    {
        $this->finish(DeploymentStatus::Failed, __('This is our fault: publishing stopped before it finished. Your app online may not have changed. Try again.'), 'ours', $exception);
    }

    /**
     * Run the setup steps, stopping at the first failure, then every check,
     * and record each result on the deployment as it finishes, so the owner
     * sees which check runs.
     */
    protected function passes(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace): bool
    {
        /** @var list<array{name: string, command: list<string>, timeout: int, needs?: string}> $setup */
        $setup = config('builder.verification.setup', []);

        /** @var list<array{name: string, command: list<string>, timeout: int, needs?: string}> $checks */
        $checks = config('builder.verification.checks', []);

        // A step the app has no use for (no package.json, say) is left out.
        // Each is asked just before it runs: what a step needs, such as
        // vendor/bin/phpstan, is there only after the installs.
        $needs = app(CheckStepNeeds::class);

        $results = [];
        $passed = true;

        foreach ($setup as $step) {
            if (! $needs->met($workspace, $step)) {
                continue;
            }

            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $results[] = $this->result($step['name'], $command);
            $this->deployment->update(['checks' => $results]);

            if (! end($results)['passed']) {
                return false;
            }
        }

        foreach ($checks as $step) {
            if (! $needs->met($workspace, $step)) {
                continue;
            }

            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $results[] = $this->result($step['name'], $command);
            $passed = $passed && end($results)['passed'];
            $this->deployment->update(['checks' => $results]);
        }

        return $passed;
    }

    /**
     * Record how a step went, and what a failed one said, so a fix can be
     * asked for from it. The output is for the builder, not the owner.
     *
     * @return array{name: string, passed: bool, output?: string}
     */
    protected function result(string $name, WorkspaceCommand $command): array
    {
        if ($command->exit_code === 0 && ! $command->timed_out) {
            return ['name' => $name, 'passed' => true];
        }

        // The end is where test runners and installers say what went wrong.
        $output = Str::substr(trim($command->output."\n".$command->error_output), -3000);

        return ['name' => $name, 'passed' => false, 'output' => Secrets::redact($command->timed_out ? "It ran out of time.\n".$output : $output)];
    }

    /**
     * Finish the deployment with a status and, when it failed, why in the
     * owner's words, whose to put right, and what was said behind it. That
     * text is for Details only, without credentials or secrets.
     *
     * @param  'settings'|'ours'|null  $cause
     */
    protected function finish(DeploymentStatus $status, ?string $error = null, ?string $cause = null, ?Throwable $behind = null): void
    {
        $details = $behind === null ? null : Secrets::redact(Str::limit(ProjectRepository::withoutCredentials(trim($behind->getMessage()), (string) $this->deployment->project->deploy_remote), 2000));

        $this->deployment->update(['status' => $status, 'error' => $error, 'error_cause' => $cause, 'error_details' => $details ?: null, 'finished_at' => now()]);
    }
}
