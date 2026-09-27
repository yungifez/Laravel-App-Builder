<?php

namespace App\Jobs;

use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\VerificationStatus;
use App\Features\AcceptanceSuite;
use App\Features\TestMap;
use App\Features\TestReport;
use App\Models\FeatureRequest;
use App\Models\TestObservation;
use App\Models\Verification;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Projects\ProjectRepository;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\WorkspaceFiles;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class VerifyFeatureRequest implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: installs plus the full check suite.
     * Keep the queue connection's retry_after above this value.
     */
    public int $timeout = 3600;

    /**
     * Verification is not retried automatically; the owner can run it again.
     */
    public int $tries = 1;

    /**
     * Maximum characters of command output kept per result.
     */
    protected const OUTPUT_TAIL = 4000;

    public const OUTCOME_PASSED = 'passed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_ERRORED = 'errored';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_NOT_APPLICABLE = 'not_applicable';

    /**
     * @var list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string, tests?: list<array{file: string, name: string, outcome: string}>}>
     */
    protected array $results = [];

    /**
     * Create a new job instance.
     */
    public function __construct(public Verification $verification)
    {
        // On a queue of its own, a change's checks start as soon as it is
        // built, instead of waiting behind other changes being built.
        $this->onQueue(config('builder.verification.queue'));
    }

    /**
     * Copy the project into a fresh workspace, apply the change and its
     * ancestors, run setup and checks, then the protected acceptance suite,
     * and record every result.
     *
     * The verification only passes when every check passes and the protected
     * acceptance suite passes. Without an applicable suite it is "unverified".
     */
    public function handle(
        WorkspaceManager $workspaces,
        ProvisionWorkspace $provisionWorkspace,
        RunWorkspaceCommand $runWorkspaceCommand,
        DestroyWorkspace $destroyWorkspace,
        ProjectRepository $repository,
        WorkspaceFiles $workspaceFiles,
    ): void {
        $featureRequest = $this->verification->featureRequest;
        $project = $featureRequest->project;
        $workspace = null;

        $this->verification->update(['status' => VerificationStatus::Running, 'started_at' => now()]);

        try {
            $workspace = $provisionWorkspace->handle($project->owner, (string) config('builder.verification.workspace_driver'));
            $this->verification->update(['workspace_id' => $workspace->id]);

            $driver = $workspaces->driver($workspace->driver);
            $repository->withCheckout($project, $featureRequest->base_revision, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

            foreach ($featureRequest->lineage() as $position => $request) {
                $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $request->patch);

                $command = $runWorkspaceCommand->handle($workspace, ['git', 'apply', '--whitespace=nowarn', $patch], 120);

                if (! $this->record("Apply change #{$request->id}", 'apply', $command)) {
                    $this->skipRemaining(['setup', 'checks'], $featureRequest);
                    $this->finish(VerificationStatus::Errored, __('The change does not apply to the project.'));

                    return;
                }
            }

            $runWorkspaceCommand->handle($workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30);

            if (! $this->runSteps($driver, $runWorkspaceCommand, $workspace, 'setup')) {
                $this->skipRemaining(['checks'], $featureRequest);
                $this->finish(VerificationStatus::Errored, __('A setup step failed, so the checks did not run.'));

                return;
            }

            $workspaceFiles->sync($project, $workspace);

            $checksPassed = $this->runSteps($driver, $runWorkspaceCommand, $workspace, 'checks', stopOnFailure: false);

            // Before the protected acceptance tests are copied in, so the map
            // only ever holds the project's own tests.
            $this->observeTests($driver, $runWorkspaceCommand, $workspace, $featureRequest);
            $acceptance = $this->runAcceptance($driver, $runWorkspaceCommand, $workspace, $featureRequest);

            $this->finish(match (true) {
                $acceptance === self::OUTCOME_ERRORED => VerificationStatus::Errored,
                ! $checksPassed || $acceptance === self::OUTCOME_FAILED => VerificationStatus::Failed,
                $acceptance === self::OUTCOME_NOT_APPLICABLE => VerificationStatus::Unverified,
                default => VerificationStatus::Passed,
            });
        } catch (Throwable $exception) {
            report($exception);

            $this->finish(VerificationStatus::Errored, $exception->getMessage());
        } finally {
            if ($workspace !== null) {
                rescue(fn () => $destroyWorkspace->handle($workspace));
            }
        }
    }

    /**
     * Record an unexpected failure (for example a worker timeout) on the verification.
     */
    public function failed(?Throwable $exception): void
    {
        $this->verification->update([
            'status' => VerificationStatus::Errored,
            'error' => __('Verification stopped unexpectedly.'),
            'finished_at' => now(),
        ]);

        app(CompleteRunVerification::class)->handle($this->verification);
    }

    /**
     * Run the configured setup commands or checks. A step with a "report"
     * writes a JUnit report there, and the tests it lists are recorded with
     * the step's result: what actually ran, not what anyone says ran.
     */
    protected function runSteps(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, string $stage, bool $stopOnFailure = true): bool
    {
        $allSucceeded = true;
        $steps = $this->configuredSteps($stage);

        foreach ($steps as $index => $step) {
            $report = $step['report'] ?? null;

            if ($report !== null) {
                $runWorkspaceCommand->handle($workspace, ['rm', '-f', $report], 30);
            }

            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);
            $tests = $report === null ? null : TestReport::fromJunit((string) rescue(fn () => $driver->readFile((string) $workspace->driver_id, $report), '', report: false));

            if (! $this->record($step['name'], $stage, $command, $tests)) {
                $allSucceeded = false;

                if ($stopOnFailure) {
                    foreach (array_slice($steps, $index + 1) as $skipped) {
                        $this->addResult($skipped['name'], $stage, self::OUTCOME_SKIPPED, output: __('Not run because an earlier step failed.'));
                    }

                    return false;
                }
            }
        }

        return $allSucceeded;
    }

    /**
     * When the suite check passed, run the suite again with code coverage and
     * keep which tests ran which code (direction 22). This is evidence for
     * Effects and impact, never a check: whatever happens here, the result
     * of the verification stays what the checks said.
     */
    protected function observeTests(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): void
    {
        /** @var array{enabled: bool, command: list<string>, timeout: int, report: string, listing: string} $config */
        $config = config('builder.verification.test_map');
        $suite = collect($this->results)->firstWhere('name', config('builder.verification.suite_check'));

        if (! $config['enabled'] || ($suite['outcome'] ?? null) !== self::OUTCOME_PASSED) {
            return;
        }

        rescue(function () use ($driver, $runWorkspaceCommand, $workspace, $featureRequest, $config) {
            $command = $runWorkspaceCommand->handle($workspace, $config['command'], $config['timeout']);
            $read = fn (string $path) => rescue(fn () => $driver->readFile((string) $workspace->driver_id, $path), null, report: false);
            $map = $this->outcome($command) === self::OUTCOME_PASSED ? TestMap::parse((string) $read($config['report']), $read($config['listing'])) : null;

            TestObservation::create([
                'project_id' => $featureRequest->project_id,
                'feature_request_id' => $featureRequest->id,
                'verification_id' => $this->verification->id,
                'tests' => $map->tests ?? [],
                'files' => $map->files ?? [],
                'error' => match (true) {
                    $map === null => __('The tests could not run with code coverage.'),
                    $map->isEmpty() => __('The tests ran, but no code coverage was recorded.'),
                    default => null,
                },
            ]);
        });
    }

    /**
     * Replace tests/Acceptance with the platform-owned suite for the change,
     * run it with the platform's runner configuration, and return its outcome.
     */
    protected function runAcceptance(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, FeatureRequest $featureRequest): string
    {
        $tests = $featureRequest->acceptance ?? [];

        if ($tests === []) {
            $this->addResult(__('Protected acceptance tests'), 'acceptance', self::OUTCOME_NOT_APPLICABLE, output: __('No protected acceptance tests apply to this change.'));

            return self::OUTCOME_NOT_APPLICABLE;
        }

        $suite = AcceptanceSuite::fromConfig();

        try {
            $files = $suite->files($tests);
        } catch (Throwable $exception) {
            $this->addResult(__('Protected acceptance tests'), 'acceptance', self::OUTCOME_ERRORED, output: $exception->getMessage());

            return self::OUTCOME_ERRORED;
        }

        $runWorkspaceCommand->handle($workspace, ['rm', '-rf', AcceptanceSuite::WORKSPACE_DIRECTORY], 30);

        foreach ($files as $path => $contents) {
            $driver->writeFile((string) $workspace->driver_id, $path, $contents);
        }

        $command = $runWorkspaceCommand->handle($workspace, $suite->command(), (int) config('builder.verification.acceptance.timeout'));

        $this->record(__('Protected acceptance tests'), 'acceptance', $command);

        return $this->outcome($command);
    }

    /**
     * Record every step of the given stages (and the acceptance suite) as skipped.
     *
     * @param  list<string>  $stages
     */
    protected function skipRemaining(array $stages, FeatureRequest $featureRequest): void
    {
        foreach ($stages as $stage) {
            foreach ($this->configuredSteps($stage) as $step) {
                $this->addResult($step['name'], $stage, self::OUTCOME_SKIPPED, output: __('Not run because an earlier step failed.'));
            }
        }

        if (($featureRequest->acceptance ?? []) !== []) {
            $this->addResult(__('Protected acceptance tests'), 'acceptance', self::OUTCOME_SKIPPED, output: __('Not run because an earlier step failed.'));
        }
    }

    /**
     * Get the configured setup commands or checks.
     *
     * @return list<array{name: string, command: list<string>, timeout: int, report?: string}>
     */
    protected function configuredSteps(string $stage): array
    {
        /** @var list<array{name: string, command: list<string>, timeout: int, report?: string}> $steps */
        $steps = config("builder.verification.{$stage}", []);

        return $steps;
    }

    /**
     * Add a command's outcome to the results and report whether it passed.
     *
     * @param  list<array{file: string, name: string, outcome: string}>|null  $tests  The tests the command ran, when it reports them
     */
    protected function record(string $name, string $stage, WorkspaceCommand $command, ?array $tests = null): bool
    {
        $outcome = $this->outcome($command);

        $this->addResult(
            $name,
            $stage,
            $outcome,
            exitCode: $command->exit_code,
            timedOut: $command->timed_out,
            durationMs: $command->duration_ms,
            output: $this->withoutTerminalCodes($command->output."\n".$command->error_output),
            tests: $tests,
        );

        return $outcome === self::OUTCOME_PASSED;
    }

    /**
     * Classify a finished command: a timeout is an error, not a failed check.
     */
    protected function outcome(WorkspaceCommand $command): string
    {
        return match (true) {
            $command->timed_out => self::OUTCOME_ERRORED,
            $command->exit_code === 0 => self::OUTCOME_PASSED,
            default => self::OUTCOME_FAILED,
        };
    }

    /**
     * Append a result and save progress so the owner sees it while the run continues.
     *
     * @param  list<array{file: string, name: string, outcome: string}>|null  $tests
     */
    protected function addResult(string $name, string $stage, string $outcome, ?int $exitCode = null, bool $timedOut = false, int $durationMs = 0, string $output = '', ?array $tests = null): void
    {
        $output = trim($output);

        $this->results[] = [
            'name' => $name,
            'stage' => $stage,
            'outcome' => $outcome,
            'exit_code' => $exitCode,
            'timed_out' => $timedOut,
            'duration_ms' => $durationMs,
            'output' => mb_strlen($output) > self::OUTPUT_TAIL ? '…'.Str::substr($output, -self::OUTPUT_TAIL) : $output,
            ...($tests === null ? [] : ['tests' => $tests]),
        ];

        $this->verification->update(['results' => $this->results]);
    }

    /**
     * Remove ANSI colour and cursor sequences so output reads as plain text.
     */
    protected function withoutTerminalCodes(string $output): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output);
    }

    /**
     * Store the final status.
     */
    protected function finish(VerificationStatus $status, ?string $error = null): void
    {
        $this->verification->update([
            'status' => $status,
            'results' => $this->results,
            'error' => $error,
            'finished_at' => now(),
        ]);

        app(CompleteRunVerification::class)->handle($this->verification);
    }
}
