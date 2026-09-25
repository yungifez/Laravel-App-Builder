<?php

namespace App\Jobs;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\VerificationStatus;
use App\Models\Verification;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
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

    /**
     * @var list<array{name: string, stage: string, exit_code: int, timed_out: bool, duration_ms: int, output: string}>
     */
    protected array $results = [];

    /**
     * Create a new job instance.
     */
    public function __construct(public Verification $verification) {}

    /**
     * Copy the project into a fresh workspace, apply the change and its
     * ancestors, run setup and checks, and record every result.
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
                    $this->finish(VerificationStatus::Errored, __('The change does not apply to the project.'));

                    return;
                }
            }

            $runWorkspaceCommand->handle($workspace, ['rm', '-rf', '.builder'], 30);

            if (! $this->runSteps($runWorkspaceCommand, $workspace, 'setup')) {
                $this->finish(VerificationStatus::Errored, __('A setup step failed, so the checks did not run.'));

                return;
            }

            $passed = $this->runSteps($runWorkspaceCommand, $workspace, 'checks', stopOnFailure: false);

            $this->finish($passed ? VerificationStatus::Passed : VerificationStatus::Failed);
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
    }

    /**
     * Run the configured setup commands or checks.
     */
    protected function runSteps(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, string $stage, bool $stopOnFailure = true): bool
    {
        /** @var list<array{name: string, command: list<string>, timeout: int}> $steps */
        $steps = config("builder.verification.{$stage}", []);
        $allSucceeded = true;

        foreach ($steps as $step) {
            $command = $runWorkspaceCommand->handle($workspace, $step['command'], $step['timeout']);

            if (! $this->record($step['name'], $stage, $command)) {
                $allSucceeded = false;

                if ($stopOnFailure) {
                    return false;
                }
            }
        }

        return $allSucceeded;
    }

    /**
     * Add a command's outcome to the results and report whether it succeeded.
     */
    protected function record(string $name, string $stage, WorkspaceCommand $command): bool
    {
        $output = trim($this->withoutTerminalCodes($command->output."\n".$command->error_output));

        $this->results[] = [
            'name' => $name,
            'stage' => $stage,
            'exit_code' => $command->exit_code,
            'timed_out' => $command->timed_out,
            'duration_ms' => $command->duration_ms,
            'output' => mb_strlen($output) > self::OUTPUT_TAIL ? '…'.Str::substr($output, -self::OUTPUT_TAIL) : $output,
        ];

        $this->verification->update(['results' => $this->results]);

        return $command->exit_code === 0 && ! $command->timed_out;
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
    }
}
