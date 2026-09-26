<?php

namespace App\Jobs;

use App\Actions\Previews\AllocatePreviewPort;
use App\Actions\Previews\StopPreview;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\PreviewStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
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
     * follows up on (a project preview has none), prepare the app, and start
     * its web server. An editable preview is marked for point-and-edit and
     * rebuilt first. A duplicate delivery, or one for a preview already
     * stopped, does nothing.
     */
    public function handle(
        WorkspaceManager $workspaces,
        ProvisionWorkspace $provisionWorkspace,
        RunWorkspaceCommand $runWorkspaceCommand,
        AllocatePreviewPort $allocatePreviewPort,
        StopPreview $stopPreview,
        ProjectRepository $repository,
    ): void {
        if ($this->preview->fresh()?->status !== PreviewStatus::Starting) {
            return;
        }

        $featureRequest = $this->preview->featureRequest;
        $project = $this->preview->project;

        try {
            $workspace = $provisionWorkspace->handle($project->owner, (string) config('builder.preview.workspace_driver'));
            $this->preview->update(['workspace_id' => $workspace->id]);

            $driver = $workspaces->driver($workspace->driver);
            $repository->withCheckout($project, $featureRequest === null ? $this->preview->revision : $featureRequest->base_revision, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

            foreach ($featureRequest?->lineage() ?? [] as $position => $request) {
                $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $request->patch);

                $this->run($runWorkspaceCommand, $workspace, ['git', 'apply', '--whitespace=nowarn', $patch], 120, __('Change #:id does not apply to the project.', ['id' => $request->id]));
            }

            $this->run($runWorkspaceCommand, $workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30, __('The workspace could not be prepared.'));

            /** @var list<array{name: string, command: list<string>, timeout: int}> $setup */
            $setup = config('builder.preview.setup', []);

            foreach ($setup as $step) {
                $this->run($runWorkspaceCommand, $workspace, $step['command'], $step['timeout'], __('The setup step ":name" failed.', ['name' => $step['name']]));
            }

            if ($this->preview->editable) {
                $this->run($runWorkspaceCommand, $workspace, self::locatorCommand(), 300, __('The preview could not be prepared for editing.'));

                /** @var list<array{name: string, command: list<string>, timeout: int}> $rebuild */
                $rebuild = config('builder.preview.rebuild', []);

                foreach ($rebuild as $step) {
                    $this->run($runWorkspaceCommand, $workspace, $step['command'], $step['timeout'], __('The setup step ":name" failed.', ['name' => $step['name']]));
                }
            }

            $port = $allocatePreviewPort->handle();
            $this->preview->update(['port' => $port]);

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
     * Get the command that marks elements with their source location.
     *
     * @return list<string>
     */
    public static function locatorCommand(): array
    {
        return [
            Config::string('builder.preview.locator.node'),
            Config::string('builder.preview.locator.path'),
            ...array_values(array_filter(Config::array('builder.preview.locator.directories'), is_string(...))),
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
            $output = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $result->error_output ?: $result->output);

            throw new RuntimeException(trim($reason."\n".trim(mb_substr($output, -2000))));
        }
    }
}
