<?php

namespace App\Jobs;

use App\Actions\Previews\AllocatePreviewPort;
use App\Actions\Previews\StopPreview;
use App\Actions\Workspaces\LoadProjectIntoWorkspace;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\PreviewStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class StartPreview implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: installs and the frontend build.
     */
    public int $timeout = 3600;

    /**
     * A failed start is not retried; the owner can start the preview again.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Preview $preview) {}

    /**
     * Copy the project into a workspace, apply the change and every change it
     * follows up on, prepare the app, and start its web server. A duplicate
     * delivery, or one for a preview already stopped, does nothing.
     */
    public function handle(
        WorkspaceManager $workspaces,
        ProvisionWorkspace $provisionWorkspace,
        RunWorkspaceCommand $runWorkspaceCommand,
        AllocatePreviewPort $allocatePreviewPort,
        StopPreview $stopPreview,
        LoadProjectIntoWorkspace $loadProjectIntoWorkspace,
    ): void {
        if ($this->preview->fresh()?->status !== PreviewStatus::Starting) {
            return;
        }

        $featureRequest = $this->preview->featureRequest;
        $project = $featureRequest->project;

        try {
            $workspace = $provisionWorkspace->handle($project->owner, (string) config('builder.preview.workspace_driver'));
            $this->preview->update(['workspace_id' => $workspace->id]);

            $driver = $workspaces->driver($workspace->driver);

            $output = '';
            $failed = $loadProjectIntoWorkspace->handle(
                $workspace,
                $project,
                $featureRequest->lineage(),
                function (FeatureRequest $change, WorkspaceCommand $command) use (&$output) {
                    $output = $command->error_output ?: $command->output;
                },
            );

            if ($failed !== null) {
                throw new RuntimeException($this->failure(__('Change #:id does not apply to the project.', ['id' => $failed->id]), (string) $output));
            }

            /** @var list<array{name: string, command: list<string>, timeout: int}> $setup */
            $setup = config('builder.preview.setup', []);

            foreach ($setup as $step) {
                $this->run($runWorkspaceCommand, $workspace, $step['command'], $step['timeout'], __('The setup step ":name" failed.', ['name' => $step['name']]));
            }

            $port = $allocatePreviewPort->handle($this->preview);

            $driver->startService((string) $workspace->driver_id, $this->serverCommand($port), $port);
            $upstream = $driver->serviceUrl((string) $workspace->driver_id, $port);

            $this->waitUntilReady($upstream);

            $this->preview->update([
                'status' => PreviewStatus::Ready,
                'upstream_url' => $upstream,
                'ready_at' => now(),
                'last_seen_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $this->preview->update(['status' => PreviewStatus::Failed, 'error' => $exception->getMessage()]);
            $stopPreview->handle($this->preview);
        }
    }

    /**
     * Record an unexpected failure (for example a worker timeout) on the preview.
     */
    public function failed(?Throwable $exception): void
    {
        $this->preview->update(['status' => PreviewStatus::Failed, 'error' => __('The preview stopped unexpectedly.')]);

        app(StopPreview::class)->handle($this->preview);
    }

    /**
     * Build the web server command: PHP's built-in server with Laravel's
     * router, told its public URL through the environment.
     *
     * @return list<string>
     */
    protected function serverCommand(int $port): array
    {
        /** @var array<string, string> $environment */
        $environment = config('builder.preview.environment', []);
        $environment['APP_URL'] = rtrim($this->preview->url(), '/');
        $environment['PHP_CLI_SERVER_WORKERS'] ??= '4';

        // Laravel's router script serves from the current directory, so the
        // server starts inside public/, as `artisan serve` does.
        return [
            'env',
            ...array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($environment), $environment),
            'sh', '-c', 'cd public && exec "$@"', 'sh',
            'php', '-S', config('builder.preview.listen_host').":{$port}",
            '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
        ];
    }

    /**
     * Wait for the app's health endpoint to answer.
     *
     * @throws RuntimeException when it does not answer in time.
     */
    protected function waitUntilReady(string $upstream): void
    {
        $attempts = max(1, (int) config('builder.preview.boot_seconds') * 2);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $healthy = rescue(fn () => Http::timeout(2)->get("{$upstream}/up")->successful(), false, report: false);

            if ($healthy) {
                return;
            }

            Sleep::for(500)->milliseconds();
        }

        throw new RuntimeException(__('The app did not start in time.'));
    }

    /**
     * Run a preparation command and stop with the given reason if it fails.
     *
     * @param  list<string>  $command
     *
     * @throws RuntimeException
     */
    protected function run(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, array $command, int $timeoutSeconds, string $reason): void
    {
        $result = $runWorkspaceCommand->handle($workspace, $command, $timeoutSeconds);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw new RuntimeException($this->failure($reason, $result->error_output ?: $result->output));
        }
    }

    /**
     * Describe a failed preparation command: the reason, then the end of its
     * output as plain text.
     */
    protected function failure(string $reason, string $output): string
    {
        $output = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output);

        return trim($reason."\n".trim(mb_substr($output, -2000)));
    }
}
