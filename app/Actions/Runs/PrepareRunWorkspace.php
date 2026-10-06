<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\CheckStepNeeds;
use App\Actions\Workspaces\DescribeEnvironment;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\RunStatus;
use App\Enums\WorkspaceStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\RunLease;
use App\Runs\SetupFailure;
use App\Workspaces\Drivers\CopyExclusions;
use App\Workspaces\WorkspaceFiles;
use App\Workspaces\WorkspaceManager;
use Closure;
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
        private CheckStepNeeds $checkStepNeeds,
        private DescribeEnvironment $describeEnvironment,
    ) {}

    /**
     * Get the run's workspace, preparing one if it has none: copy the project
     * in as of the request's base revision, apply the changes the request
     * follows up on, add the notes, commit that as the baseline the run's
     * change is measured against, run the setup, add the saved workspace
     * files, then note what the workspace builds with.
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
        $workspace = $this->provisionWorkspace->handle($project->owner, (string) config('builder.construction.workspace_driver'), $this->whileWaiting($run, $lease));

        try {
            $driver = $this->workspaces->driver($workspace->driver);
            $this->repository->withCheckout($project, $featureRequest->base_revision, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

            foreach (array_slice($featureRequest->lineage(), 0, -1) as $position => $ancestor) {
                $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $ancestor->patch);

                $this->run($workspace, ['git', 'apply', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), $patch], SetupFailure::changeNoLongerFits(), __('Change #:id no longer applies to the project.', ['id' => $ancestor->id]));
            }

            $this->run($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], SetupFailure::ours(), __('The workspace could not be prepared.'));
            $this->workspaceFiles->placeNotes($featureRequest, $workspace);

            // The agent can read this history, so it names only the owner.
            $identity = ['-c', "user.name={$project->owner->name}", '-c', "user.email={$project->owner->email}", '-c', 'commit.gpgsign=false'];
            $this->run($workspace, ['git', 'init', '--quiet'], SetupFailure::ours(), __('The workspace could not be prepared.'));
            $this->run($workspace, ['git', 'add', '--all'], SetupFailure::ours(), __('The workspace could not be prepared.'));
            $this->run($workspace, ['git', ...$identity, 'commit', '--quiet', '--allow-empty', '--no-verify', '-m', 'Baseline'], SetupFailure::ours(), __('The workspace could not be prepared.'));
            $workspace->update(['baseline_commit' => trim($this->run($workspace, ['git', 'rev-parse', 'HEAD'], SetupFailure::ours(), __('The workspace could not be prepared.')))]);

            // A change the owner asked to go on keeps the code it made: laid
            // on after the baseline, so it is part of the change. Read from
            // the event, which stays when the run's feedback moves on.
            if (($run->events()->where('type', 'resumed')->first()?->data['made_so_far'] ?? false) === true && trim((string) $featureRequest->patch) !== '') {
                $driver->writeFile((string) $workspace->driver_id, FeatureRequest::LINEAGE_DIRECTORY.'/resumed.patch', (string) $featureRequest->patch);
                $this->run($workspace, ['git', 'apply', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), FeatureRequest::LINEAGE_DIRECTORY.'/resumed.patch'], SetupFailure::changeNoLongerFits(), __('The code made before the stop no longer applies to the project.'));
                $this->run($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], SetupFailure::ours(), __('The workspace could not be prepared.'));
            }
            // Inside .git, so the pictures are there to look at but never part of the change.
            $this->workspaceFiles->placeImages($featureRequest, $workspace);

            /** @var list<array{name: string, command: list<string>, timeout: int, needs?: string}> $setup */
            $setup = config('builder.construction.setup', []);

            foreach ($setup as $step) {
                // As for previews and checks, a step for something the app
                // does not use (route helpers without Wayfinder) is skipped.
                // Asked just before the step, after the installs it may need.
                if (! $this->checkStepNeeds->met($workspace, $step)) {
                    continue;
                }

                $this->run(
                    $workspace,
                    $step['command'],
                    fn (bool $timedOut) => SetupFailure::step($step['name'], $timedOut),
                    fn (bool $timedOut) => __('The setup step ":name" :outcome.', ['name' => $step['name'], 'outcome' => $timedOut ? __('ran out of time') : __('failed')]),
                    $step['timeout'],
                );
            }

            $this->workspaceFiles->sync($project, $workspace);
            // After the setup, so the lockfiles are the ones the run builds with.
            $environment = $this->describeEnvironment->handle($workspace);

            DB::transaction(function () use ($run, $lease, $workspace, $environment) {
                $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

                $lease->assertHeldOn($locked);

                $locked->workspace_id = $workspace->id;
                $locked->environment = $environment;
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
     * Run a preparation command and return its output, or stop if it fails.
     * The error's first line is the owner's; the reason and the end of what
     * the command printed follow it for operators.
     *
     * @param  list<string>  $command
     * @param  string|Closure(bool): string  $owner  given whether the command ran out of time
     * @param  string|Closure(bool): string  $reason  given whether the command ran out of time
     *
     * @throws ConstructionFailed
     */
    protected function run(Workspace $workspace, array $command, string|Closure $owner, string|Closure $reason, int $timeoutSeconds = 120): string
    {
        $result = $this->runWorkspaceCommand->handle($workspace, $command, $timeoutSeconds);

        if ($result->exit_code !== 0 || $result->timed_out) {
            // Artisan commands write their errors to the normal output, so
            // the reason is there when the error output is empty.
            $output = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $result->error_output ?: $result->output);
            $owner = is_string($owner) ? $owner : $owner($result->timed_out);
            $reason = is_string($reason) ? $reason : $reason($result->timed_out);

            throw new ConstructionFailed($owner."\n".trim($reason.' '.trim(mb_substr($output, -2000))));
        }

        return $result->output;
    }

    /**
     * Make the step that runs while the workspace waits for a machine with
     * room: keep the lease, which would run out during a long wait, stop
     * when the owner cancels, and tell the owner once what the wait is for.
     *
     * @return Closure(): void
     */
    protected function whileWaiting(Run $run, RunLease $lease): Closure
    {
        $told = false;

        return function () use ($run, $lease, &$told) {
            DB::transaction(function () use ($run, $lease, &$told) {
                $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

                $lease->assertHeldOn($locked);

                if ($locked->status === RunStatus::Cancelling) {
                    throw RunCancelled::forRun($locked->id);
                }

                $locked->extendLease();

                if (! $told) {
                    $locked->recordEvent('waiting_for_machine');
                    $told = true;
                }
            });
        };
    }
}
