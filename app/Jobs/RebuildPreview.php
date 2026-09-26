<?php

namespace App\Jobs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Projects\ProjectRepository;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
     * A rebuild waits while another rebuild of the same preview runs.
     */
    public int $tries = 60;

    /**
     * A failed rebuild is not retried; the next edit rebuilds again.
     */
    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(public Preview $preview) {}

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("preview:{$this->preview->id}"))->releaseAfter(10)->expireAfter($this->timeout)];
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

        try {
            foreach ($repository->changedFiles($project, $preview->revision, $head) as $path => $deleted) {
                if ($deleted) {
                    $this->run($runWorkspaceCommand, $preview, ['rm', '-f', '--', $path], 30);
                } else {
                    $driver->writeFile((string) $workspace->driver_id, $path, (string) $repository->show($project, $head, $path));
                }
            }

            $this->run($runWorkspaceCommand, $preview, StartPreview::locatorCommand(), 300);

            /** @var list<array{name: string, command: list<string>, timeout: int}> $rebuild */
            $rebuild = config('builder.preview.rebuild', []);

            foreach ($rebuild as $step) {
                $this->run($runWorkspaceCommand, $preview, $step['command'], $step['timeout']);
            }

            $preview->update(['revision' => $head, 'rebuilt_at' => now(), 'error' => null]);
        } catch (Throwable $exception) {
            report($exception);

            $preview->update(['error' => __('The preview could not show your latest change. Start it again to see it.')]);
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
