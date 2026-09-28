<?php

namespace App\Jobs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Models\PreviewRebuild;
use App\Projects\ProjectRepository;
use App\Workspaces\Contracts\WorkspaceDriver;
use App\Workspaces\WorkspaceManager;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RebuildPreview implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: the frontend build.
     */
    public int $timeout = 900;

    /**
     * A failed rebuild is not retried; the next edit rebuilds again.
     */
    public int $maxExceptions = 1;

    /**
     * When the rebuild was asked for, so time spent waiting for a worker
     * shows apart from the build.
     */
    public string $queuedAt;

    /**
     * Create a new job instance.
     */
    public function __construct(public Preview $preview)
    {
        $this->queuedAt = now()->toIso8601String();

        // On the default queue, a rebuild would wait behind a model's
        // coding run, which takes minutes; the owner waits for this one.
        $this->onQueue(config('builder.preview.queue'));
    }

    /**
     * A rebuild waits while another rebuild of the same preview runs, for
     * as long as that one may take.
     */
    public function retryUntil(): CarbonImmutable
    {
        return now()->addSeconds($this->timeout * 2);
    }

    /**
     * Get the middleware the job should pass through. A waiting rebuild
     * checks again each second: a build takes a few seconds, and the
     * owner sees their change only when the next one is done.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("preview:{$this->preview->id}"))->releaseAfter(1)->expireAfter($this->timeout)];
    }

    /**
     * Bring a running editable preview up to the project's latest commit:
     * copy in the files that changed, mark them for point-and-edit again,
     * and rebuild the frontend. Several quick edits share one rebuild,
     * because each rebuild goes to whatever the latest commit is then.
     */
    public function handle(WorkspaceManager $workspaces, RunWorkspaceCommand $runWorkspaceCommand, ProjectRepository $repository): void
    {
        $preview = $this->preview->fresh();

        if ($preview === null || ! $preview->editable || $preview->status !== PreviewStatus::Ready || $preview->workspace === null || $preview->revision === null) {
            return;
        }

        $project = $preview->project;
        $head = $repository->head($project);

        if ($head === $preview->revision) {
            return;
        }

        $workspace = $preview->workspace;
        $driver = $workspaces->driver($workspace->driver);
        $changed = $repository->changedFiles($project, $preview->revision, $head);
        $record = PreviewRebuild::query()->create([
            'preview_id' => $preview->id,
            'project_id' => $project->id,
            'from_revision' => $preview->revision,
            'to_revision' => $head,
            'status' => 'running',
            'queued_at' => $this->queuedAt,
            'started_at' => now(),
        ]);

        try {
            foreach ($changed as $path => $deleted) {
                if ($deleted) {
                    $this->run($runWorkspaceCommand, $preview, ['rm', '-f', '--', $path], 30);
                } else {
                    $driver->writeFile((string) $workspace->driver_id, $path, (string) $repository->show($project, $head, $path));
                }
            }

            $this->run($runWorkspaceCommand, $preview, StartPreview::locatorCommand($preview->workspace), 300);

            /** @var list<array{name: string, command: list<string>, timeout: int}> $rebuild */
            $rebuild = config('builder.preview.rebuild', []);

            foreach ($rebuild as $step) {
                $this->run($runWorkspaceCommand, $preview, $step['command'], $step['timeout']);
            }

            $preview->update(['revision' => $head, 'rebuilt_at' => now(), 'error' => null]);
            $record->update(['status' => 'rebuilt', 'finished_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);

            $record->update(['status' => 'failed', 'finished_at' => now(), 'error' => Str::limit($exception->getMessage(), 2000)]);

            $this->restore($driver, $runWorkspaceCommand, $repository, $preview, array_keys($changed));
            $preview->update(['error' => __('The preview could not show your latest change. Start it again to see it.')]);
        }
    }

    /**
     * Put the files back as they are at the revision the preview shows. The
     * next rebuild copies only what differs from that revision, so a file
     * left from a failed build (such as a change that was then undone)
     * would otherwise break every build after it.
     *
     * @param  list<string>  $paths
     */
    protected function restore(WorkspaceDriver $driver, RunWorkspaceCommand $runWorkspaceCommand, ProjectRepository $repository, Preview $preview, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                $contents = $repository->show($preview->project, (string) $preview->revision, $path);

                if ($contents === null) {
                    $this->run($runWorkspaceCommand, $preview, ['rm', '-f', '--', $path], 30);
                } else {
                    $driver->writeFile((string) $preview->workspace?->driver_id, $path, $contents);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Run a command in the preview's workspace.
     *
     * @param  list<string>  $command
     *
     * @throws RuntimeException when it fails.
     */
    protected function run(RunWorkspaceCommand $runWorkspaceCommand, Preview $preview, array $command, int $timeoutSeconds): void
    {
        $result = $runWorkspaceCommand->handle($preview->workspace, $command, $timeoutSeconds);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw new RuntimeException(trim(mb_substr($result->error_output ?: $result->output, -2000)));
        }
    }
}
