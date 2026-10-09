<?php

namespace App\Jobs;

use App\Actions\Workspaces\CheckStepNeeds;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\HealthCheckScope;
use App\Enums\HealthCheckStatus;
use App\Models\HealthCheck;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Notifications\PackagesNeedYou;
use App\Projects\ProjectRepository;
use App\Support\Secrets;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class CheckProjectHealth implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: installs, the full checks and
     * the package lookups. Keep the queue connection's retry_after above
     * this value.
     */
    public int $timeout = 3600;

    /**
     * A failed check is not retried; the owner can check again.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public HealthCheck $healthCheck) {}

    /**
     * Run the setup, every check and the package lookups on the app's
     * version as it is, in a fresh workspace, as publishing would; or only
     * the lookups, which read the lock files and need no installs. Nothing
     * in the app changes. Each result is kept as it finishes.
     */
    public function handle(
        WorkspaceManager $workspaces,
        ProvisionWorkspace $provisionWorkspace,
        RunWorkspaceCommand $runWorkspaceCommand,
        DestroyWorkspace $destroyWorkspace,
        ProjectRepository $repository,
    ): void {
        if ($this->healthCheck->fresh()?->status !== HealthCheckStatus::Queued) {
            return;
        }

        $this->healthCheck->update(['status' => HealthCheckStatus::Running]);
        $project = $this->healthCheck->project;
        $workspace = null;

        try {
            $workspace = $provisionWorkspace->handle($project->owner, (string) config('builder.verification.workspace_driver'));
            $driver = $workspaces->driver($workspace->driver);
            $repository->withCheckout($project, $this->healthCheck->commit_sha, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

            $passed = $this->passes($runWorkspaceCommand, $workspace);

            // Lookups alone that could not be read found nothing to tell.
            if ($passed === null) {
                $this->finishErrored();

                return;
            }

            $this->healthCheck->update([
                'status' => $passed ? HealthCheckStatus::Passed : HealthCheckStatus::Failed,
                'finished_at' => now(),
            ]);

            $this->tellAboutNewProblems();
        } catch (Throwable $exception) {
            report($exception);

            $this->finishErrored();
        } finally {
            if ($workspace !== null) {
                rescue(fn () => $destroyWorkspace->handle($workspace));
            }
        }
    }

    /**
     * Record an unexpected failure (for example a worker timeout).
     */
    public function failed(?Throwable $exception): void
    {
        $this->finishErrored();
    }

    /**
     * Finish a check we could not complete. The app's own checks never
     * decided this, so it is ours to say.
     */
    protected function finishErrored(): void
    {
        $this->healthCheck->update([
            'status' => HealthCheckStatus::Errored,
            'error' => __('This is our fault: the full check stopped on our side before it finished. Nothing in your app changed. Try again.'),
            'finished_at' => now(),
        ]);
    }

    /**
     * Tell the owner once when the scheduled lookups find a package problem
     * the app's previous check did not know of. The owner asked for a full
     * check and is watching it, so that one says nothing here.
     */
    protected function tellAboutNewProblems(): void
    {
        $found = $this->healthCheck->problemPackages() ?? [];

        if ($this->healthCheck->scope !== HealthCheckScope::Packages || $found === []) {
            return;
        }

        $known = $this->healthCheck->project->healthChecks()
            ->whereKeyNot($this->healthCheck->id)
            ->whereIn('status', [HealthCheckStatus::Passed, HealthCheckStatus::Failed])
            ->latest('id')
            ->get()
            ->first(fn (HealthCheck $check) => $check->problemPackages() !== null)
            ?->problemPackages() ?? [];

        $new = array_values(array_diff($found, $known));

        if ($new !== []) {
            $this->healthCheck->project->owner->notify(new PackagesNeedYou($this->healthCheck, $new));
        }
    }

    /**
     * Run the setup steps, stopping at the first failure, then every check,
     * then the package lookups. A step the app has no use for (no
     * package.json, say) is left out. A scheduled check runs only the
     * lookups and keeps only those it could read: null when none could be.
     */
    protected function passes(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace): ?bool
    {
        $lookupsOnly = $this->healthCheck->scope === HealthCheckScope::Packages;

        /** @var list<array{name: string, command: list<string>, timeout: int, needs?: string}> $setup */
        $setup = config('builder.verification.setup', []);

        /** @var list<array{name: string, command: list<string>, timeout: int, needs?: string}> $checks */
        $checks = config('builder.verification.checks', []);

        /** @var array{enabled: bool, steps: list<array{name: string, report: string, command: list<string>, timeout: int, needs?: string}>} $security */
        $security = config('builder.verification.security');

        // Each step is asked just before it runs: what a step needs, such
        // as vendor/bin/phpstan, is there only after the installs.
        $needs = app(CheckStepNeeds::class);
        $passed = true;
        $unread = false;

        foreach ($lookupsOnly ? [] : $setup as $step) {
            if (! $needs->met($workspace, $step)) {
                continue;
            }

            $result = $this->result($step['name'], 'setup', $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']));
            $this->keep($result);

            if (! $result['passed']) {
                return false;
            }
        }

        foreach ($lookupsOnly ? [] : $checks as $step) {
            if (! $needs->met($workspace, $step)) {
                continue;
            }

            $result = $this->result($step['name'], 'check', $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']));
            $passed = $passed && $result['passed'];
            $this->keep($result);
        }

        foreach ($security['enabled'] ? $security['steps'] : [] as $step) {
            if (! $needs->met($workspace, $step)) {
                continue;
            }

            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $output = (string) $command->output;
            $problems = $command->timed_out ? null : VerifyFeatureRequest::knownProblems($step['report'], $output);

            if ($lookupsOnly && $problems === null) {
                $unread = true;

                continue;
            }

            // Null packages: the lookup itself failed, so nothing is known.
            $this->keep([
                'name' => $step['name'],
                'kind' => 'packages',
                'passed' => $problems === 0,
                'packages' => $problems === null ? null : (VerifyFeatureRequest::problemPackages($step['report'], $output) ?? []),
            ]);
            $passed = $passed && $problems === 0;
        }

        return $unread && $this->healthCheck->results === null ? null : $passed;
    }

    /**
     * Record how a step went, and what a failed one said. The output is for
     * the builder, not the owner.
     *
     * @param  'setup'|'check'  $kind
     * @return array{name: string, kind: 'setup'|'check', passed: bool, output?: string}
     */
    protected function result(string $name, string $kind, WorkspaceCommand $command): array
    {
        if ($command->exit_code === 0 && ! $command->timed_out) {
            return ['name' => $name, 'kind' => $kind, 'passed' => true];
        }

        // The end is where test runners and installers say what went wrong.
        $output = Str::substr(trim($command->output."\n".$command->error_output), -3000);

        return ['name' => $name, 'kind' => $kind, 'passed' => false, 'output' => Secrets::redact($command->timed_out ? "It ran out of time.\n".$output : $output)];
    }

    /**
     * Add a result, so the owner sees the check move along.
     *
     * @param  array<string, mixed>  $result
     */
    protected function keep(array $result): void
    {
        $this->healthCheck->update(['results' => [...$this->healthCheck->results ?? [], $result]]);
    }
}
