<?php

namespace App\Jobs;

use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\VerificationStatus;
use App\Features\AcceptanceSuite;
use App\Models\FeatureRequest;
use App\Models\Verification;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Workspaces\Contracts\WorkspaceDriver;
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
     * @var list<array{name: string, stage: string, outcome: string, exit_code: int|null, timed_out: bool, duration_ms: int, output: string}>
     */
    protected array $results = [];

    /**
     * Create a new job instance.
     */
    public function __construct(public Verification $verification) {}

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
    ): void {
        $featureRequest = $this->verification->featureRequest;
        $project = $featureRequest->project;
        $workspace = null;

        $this->verification->update(['status' => VerificationStatus::Running, 'started_at' => now()]);

        try {
            $workspace = $provisionWorkspace->handle($project->owner, (string) config('builder.verification.workspace_driver'));
            $this->verification->update(['workspace_id' => $workspace->id]);

            $driver = $workspaces->driver($workspace->driver);
            $driver->copyDirectory((string) $workspace->driver_id, $project->source_path);

            foreach ($featureRequest->lineage() as $position => $request) {
                $patch = sprintf('.builder/%02d.patch', $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $request->patch);

                $command = $runWorkspaceCommand->handle($workspace, ['git', 'apply', '--whitespace=nowarn', $patch], 120);

                if (! $this->record("Apply change #{$request->id}", 'apply', $command)) {
                    $this->skipRemaining(['setup', 'checks'], $featureRequest);
                    $this->finish(VerificationStatus::Errored, __('The change does not apply to the project.'));

                    return;
                }
            }

            $runWorkspaceCommand->handle($workspace, ['rm', '-rf', '.builder'], 30);

            if (! $this->runSteps($runWorkspaceCommand, $workspace, 'setup')) {
                $this->skipRemaining(['checks'], $featureRequest);
                $this->finish(VerificationStatus::Errored, __('A setup step failed, so the checks did not run.'));

                return;
            }

            $checksPassed = $this->runSteps($runWorkspaceCommand, $workspace, 'checks', stopOnFailure: false);
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
     * Run the configured setup commands or checks.
     */
    protected function runSteps(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, string $stage, bool $stopOnFailure = true): bool
    {
        $allSucceeded = true;
        $steps = $this->configuredSteps($stage);

        foreach ($steps as $index => $step) {
            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);

            if (! $this->record($step['name'], $stage, $command)) {
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
     * @return list<array{name: string, command: list<string>, timeout: int}>
     */
    protected function configuredSteps(string $stage): array
    {
        /** @var list<array{name: string, command: list<string>, timeout: int}> $steps */
        $steps = config("builder.verification.{$stage}", []);

        return $steps;
    }

    /**
     * Add a command's outcome to the results and report whether it passed.
     */
    protected function record(string $name, string $stage, WorkspaceCommand $command): bool
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
     */
    protected function addResult(string $name, string $stage, string $outcome, ?int $exitCode = null, bool $timedOut = false, int $durationMs = 0, string $output = ''): void
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
