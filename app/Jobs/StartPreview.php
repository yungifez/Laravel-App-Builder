<?php

namespace App\Jobs;

use App\Actions\Features\OpenChangeForDesign;
use App\Actions\Previews\AllocatePreviewPort;
use App\Actions\Previews\StopPreview;
use App\Actions\Workspaces\CheckStepNeeds;
use App\Actions\Workspaces\ProvisionWorkspace;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\PreviewStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Workspace;
use App\Previews\PreviewCouldNotStart;
use App\Previews\PreviewFailure;
use App\Projects\ProjectRepository;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\Drivers\CopyExclusions;
use App\Workspaces\RunnerDoor;
use App\Workspaces\WorkspaceFiles;
use App\Workspaces\WorkspaceManager;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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
    public function __construct(public Preview $preview)
    {
        // On a queue of its own, a preview starts while the run's checks
        // take the main worker.
        $this->onQueue(config('builder.preview.queue'));
    }

    /**
     * Copy the project into a workspace, apply the change and every change it
     * follows up on (a project preview has none), prepare the app, and start
     * its web server. An editable preview is marked for point-and-edit
     * before the build, and its build keeps watching for changes when it
     * can. A duplicate delivery, or one for a preview already
     * stopped, does nothing.
     */
    public function handle(
        WorkspaceManager $workspaces,
        ProvisionWorkspace $provisionWorkspace,
        RunWorkspaceCommand $runWorkspaceCommand,
        AllocatePreviewPort $allocatePreviewPort,
        StopPreview $stopPreview,
        ProjectRepository $repository,
        WorkspaceFiles $workspaceFiles,
        OpenChangeForDesign $openChangeForDesign,
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
            // A change the owner can design on runs from its own branch, which
            // already holds the change, the ones it follows and their edits.
            if ($featureRequest !== null && $this->preview->editable) {
                $this->preview->update(['revision' => $openChangeForDesign->handle($featureRequest)]);
            }

            $repository->withCheckout($project, $featureRequest === null || $this->preview->editable ? $this->preview->revision : $featureRequest->base_revision, fn (string $source) => $driver->copyDirectory((string) $workspace->driver_id, $source));

            foreach ($featureRequest === null || $this->preview->editable ? [] : $featureRequest->lineage() as $position => $request) {
                $patch = sprintf('%s/%02d.patch', FeatureRequest::LINEAGE_DIRECTORY, $position + 1);
                $driver->writeFile((string) $workspace->driver_id, $patch, (string) $request->patch);

                try {
                    $this->run($runWorkspaceCommand, $workspace, ['git', 'apply', '--whitespace=nowarn', ...CopyExclusions::applyFlags(), $patch], 120, PreviewFailure::changeNoLongerFits()."\n".__('Change #:id does not apply to the project.', ['id' => $request->id]));
                } catch (PreviewCouldNotStart $exception) {
                    // Starting again cannot help: the change is made again
                    // on the app as it is now.
                    $this->preview->update(['no_longer_fits' => true]);

                    throw $exception;
                }
            }

            $this->run($runWorkspaceCommand, $workspace, ['rm', '-rf', FeatureRequest::LINEAGE_DIRECTORY], 30, PreviewFailure::ours()."\n".__('The workspace could not be prepared.'));

            foreach (self::steps('setup', $workspace) as $step) {
                $this->runStep($runWorkspaceCommand, $workspace, $step);
            }

            $workspaceFiles->sync($project, $workspace);

            if ($this->preview->editable) {
                $this->run($runWorkspaceCommand, $workspace, self::locatorCommand($workspace), 300, PreviewFailure::ours('getting your app ready for editing')."\n".__('The preview could not be prepared for editing.'));
            }

            if (! ($this->preview->editable && $this->startWatching($driver, $runWorkspaceCommand, $workspace))) {
                foreach (self::steps('build', $workspace) as $step) {
                    $this->runStep($runWorkspaceCommand, $workspace, $step);
                }
            }

            $port = $allocatePreviewPort->handle();
            $this->preview->update(['port' => $port]);

            $upstream = $driver->serviceUrl((string) $workspace->driver_id, $port);
            $driver->startService((string) $workspace->driver_id, $this->serverCommand($port, RunnerDoor::listenHost($upstream)), $port);

            $this->waitUntilReady($upstream);

            $this->preview->update([
                'status' => PreviewStatus::Ready,
                'upstream_url' => $upstream,
                'ready_at' => now(),
                'last_seen_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            // Anything we did not word for the owner is ours.
            $this->preview->update(['status' => PreviewStatus::Failed, 'error' => $exception instanceof PreviewCouldNotStart
                ? $exception->getMessage()
                : PreviewFailure::ours()."\n".$exception->getMessage()]);
            $stopPreview->handle($this->preview);
        }
    }

    /**
     * Record an unexpected failure (for example a worker timeout) on the preview.
     */
    public function failed(?Throwable $exception): void
    {
        $this->preview->update(['status' => PreviewStatus::Failed, 'error' => PreviewFailure::stopped()."\n".__('The preview stopped unexpectedly.')]);

        app(StopPreview::class)->handle($this->preview);
    }

    /**
     * Build the web server command: PHP's built-in server with Laravel's
     * router, told its public URL through the environment. It listens only
     * at the address the control plane reaches it at (on a runner hosted
     * apart, its private network address), unless "listen_host" says
     * otherwise.
     *
     * @return list<string>
     */
    protected function serverCommand(int $port, string $reachedAt): array
    {
        $environment = $this->preview->environment();
        $environment['PHP_CLI_SERVER_WORKERS'] ??= '4';
        $recorder = $this->recorder();

        // Laravel's router script serves from the current directory, so the
        // server starts inside public/, as `artisan serve` does.
        return [
            'env',
            ...array_map(fn (string $name, string $value) => "{$name}={$value}", array_keys($environment), $environment),
            'sh', '-c', $recorder === null ? 'cd public && exec "$@"' : $recorder['shell'], 'sh',
            ...($recorder === null ? [] : [$recorder['directory']]),
            'php', ...($recorder === null ? [] : ['-d', 'auto_prepend_file='.$recorder['prepend']]),
            '-S', (config('builder.preview.listen_host') ?? $reachedAt).":{$port}",
            '../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
        ];
    }

    /**
     * How the server loads the trace recorder, which "What happened" and
     * "What if it fails" read: the recorder's folder is made in the
     * workspace, and a fault the owner set before this start is dropped,
     * so the app starts with all working. Nothing when the recorder is
     * off or not in the image.
     *
     * @return array{shell: string, directory: string, prepend: string}|null
     */
    protected function recorder(): ?array
    {
        if (! Config::boolean('builder.preview.recorder.enabled')) {
            return null;
        }

        $prepend = Config::string('builder.preview.recorder.prepend');

        return [
            // $1 is the recorder's folder; the rest is the server command.
            'shell' => 'if [ -f '.escapeshellarg($prepend).' ]; then mkdir -p "$1" && rm -f "$1/fault.json" && export TRACE_RECORDER_DIR="$PWD/$1"; fi; shift; cd public && exec "$@"',
            'directory' => trim(Config::string('builder.preview.recorder.directory'), '/'),
            'prepend' => $prepend,
        ];
    }

    /**
     * Get the "setup" or "build" steps the app in the workspace can run, so
     * an app with no package.json (Blade or Livewire with no build) still
     * starts.
     *
     * @return list<array{name: string, command: list<string>, timeout: int, needs?: string}>
     */
    public static function steps(string $stage, Workspace $workspace): array
    {
        /** @var list<array{name: string, command: list<string>, timeout: int, needs?: string}> $steps */
        $steps = config("builder.preview.{$stage}", []);

        return app(CheckStepNeeds::class)->filter($workspace, $steps);
    }

    /**
     * Get the command that marks elements with their source location: in the
     * app, or in the files staged under "stage".
     *
     * @return list<string>
     */
    public static function locatorCommand(?Workspace $workspace = null, ?string $stage = null): array
    {
        return [
            Config::string('builder.preview.locator.node'),
            self::toolPath($workspace, 'preview_locator', 'builder.preview.locator.path'),
            ...($stage === null ? [] : ['--stage', $stage]),
            ...array_values(array_filter(Config::array('builder.preview.locator.directories'), is_string(...))),
        ];
    }

    /**
     * Get the command that runs the build in watch mode ("watch"), waits for
     * its first build ("wait") or moves staged files in and waits for their
     * build ("place").
     *
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public static function watchCommand(?Workspace $workspace, string $mode, array $arguments = []): array
    {
        return [
            Config::string('builder.preview.locator.node'),
            self::toolPath($workspace, 'preview_watch', 'builder.preview.watch.path'),
            $mode,
            Config::string('builder.preview.watch.directory'),
            ...$arguments,
        ];
    }

    /**
     * Get where a preview tool is: a box has its own read-only copy; the
     * other drivers use ours.
     */
    protected static function toolPath(?Workspace $workspace, string $boxKey, string $key): string
    {
        $boxPath = $workspace === null ? null : config("workspaces.drivers.{$workspace->driver}.{$boxKey}");

        return is_string($boxPath) && $boxPath !== '' ? $boxPath : Config::string($key);
    }

    /**
     * Start the frontend build in watch mode and wait for its first build,
     * which takes the place of the build steps. Each rebuild after an edit
     * then builds only what changed. A watcher that does not build in time
     * leaves the build to the build steps.
     */
    protected function startWatching(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace): bool
    {
        // The watcher is a Node build; an app with no package.json has none.
        if (! config('builder.preview.watch.enabled') || self::steps('build', $workspace) === []) {
            return false;
        }

        $timeout = (int) config('builder.preview.watch.timeout');

        $driver->startService((string) $workspace->driver_id, self::watchCommand($workspace, 'watch', [
            Config::string('builder.preview.watch.started'),
            Config::string('builder.preview.watch.done'),
            ...array_values(array_filter(Config::array('builder.preview.watch.command'), is_string(...))),
        ]), 0);

        $result = $runWorkspaceCommand->handle($workspace, self::watchCommand($workspace, 'wait', [
            (string) config('builder.preview.watch.quiet_ms'),
            (string) $timeout,
        ]), $timeout + 30);

        if ($result->exit_code !== 0 || $result->timed_out) {
            return false;
        }

        $this->preview->update(['watching' => true]);

        return true;
    }

    /**
     * Wait for the app's health endpoint to answer. An app that removed
     * Laravel's health route answers it with "not found", which already
     * shows the app is running; it is ready once its home page answers
     * without an error, even with a redirect to sign in.
     *
     * @throws PreviewCouldNotStart when it does not answer in time.
     */
    protected function waitUntilReady(string $upstream): void
    {
        $seconds = (int) config('builder.preview.boot_seconds');
        $status = null;
        // A request that cannot connect keeps the last answer, to report.
        $ask = function (string $path) use ($upstream, &$status): ?int {
            return rescue(fn () => app(RunnerDoor::class)->prepare(Http::timeout(2)->withoutRedirecting(), $upstream)->get("{$upstream}{$path}")->status(), $status, report: false);
        };

        for ($attempt = 0; $attempt < max(1, $seconds * 2); $attempt++) {
            $status = $ask('/up');

            if ($status !== null && $status >= 200 && $status < 300) {
                return;
            }

            if ($status === 404) {
                $status = $ask('/');

                if ($status !== null && $status < 500) {
                    return;
                }
            }

            Sleep::for(500)->milliseconds();
        }

        throw new PreviewCouldNotStart(PreviewFailure::notUp($status, $seconds)."\n".__('The app did not start in time (last answer: :status).', ['status' => $status ?? 'none']));
    }

    /**
     * Run a setup or build step. If it fails, the owner reads the stage it
     * stopped at and what to do; operators also get the step's name.
     *
     * @param  array{name: string, command: list<string>, timeout: int, needs?: string}  $step
     *
     * @throws PreviewCouldNotStart
     */
    protected function runStep(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, array $step): void
    {
        $this->run($runWorkspaceCommand, $workspace, $step['command'], $step['timeout'], fn (bool $timedOut) => PreviewFailure::step($step['name'], $timedOut, $step['timeout'])
            ."\n".__('The setup step ":name" :outcome.', ['name' => $step['name'], 'outcome' => $timedOut ? __('ran out of time') : __('failed')]));
    }

    /**
     * Run a preparation command and stop with the given reason if it fails.
     * The reason's first line is for the owner; the command's output after
     * it is for operators.
     *
     * @param  list<string>  $command
     * @param  string|Closure(bool): string  $reason  given whether the command ran out of time
     *
     * @throws PreviewCouldNotStart
     */
    protected function run(RunWorkspaceCommand $runWorkspaceCommand, Workspace $workspace, array $command, int $timeoutSeconds, string|Closure $reason): void
    {
        $result = $runWorkspaceCommand->handle($workspace, $command, $timeoutSeconds);

        if ($result->exit_code !== 0 || $result->timed_out) {
            $output = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $result->error_output ?: $result->output);
            $reason = is_string($reason) ? $reason : $reason($result->timed_out);

            throw new PreviewCouldNotStart(trim($reason."\n".trim(mb_substr($output, -2000))));
        }
    }
}
