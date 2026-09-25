<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use App\Models\WorkspaceCommand;
use App\Workspaces\Exceptions\WorkspaceBusyException;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RunWorkspaceCommand
{
    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Run a command in the workspace and record the result.
     *
     * Each owner may only run a limited number of commands at once across all
     * of their workspaces, so one customer app cannot take over the hosts.
     *
     * @param  list<string>  $command
     *
     * @throws WorkspaceBusyException when no command slot frees up in time.
     */
    public function handle(Workspace $workspace, array $command, ?int $timeoutSeconds = null): WorkspaceCommand
    {
        if ($workspace->status !== WorkspaceStatus::Ready || $workspace->driver_id === null) {
            throw new InvalidArgumentException("Workspace [{$workspace->id}] is not ready.");
        }

        $timeoutSeconds ??= (int) config('workspaces.commands.timeout');

        /** @var WorkspaceCommand */
        return Cache::funnel("workspaces:owner:{$workspace->user_id}")
            ->limit((int) config('workspaces.commands.per_owner'))
            ->releaseAfter($timeoutSeconds + 60)
            ->block((int) config('workspaces.commands.wait_seconds'))
            ->then(
                fn () => $this->run($workspace, $command, $timeoutSeconds),
                fn () => throw WorkspaceBusyException::forOwner($workspace->user_id),
            );
    }

    /**
     * Execute the command through the workspace's driver and store the result.
     *
     * @param  list<string>  $command
     */
    protected function run(Workspace $workspace, array $command, int $timeoutSeconds): WorkspaceCommand
    {
        $result = $this->workspaces->driver($workspace->driver)
            ->exec((string) $workspace->driver_id, $command, $timeoutSeconds);

        $workspace->update(['last_activity_at' => now()]);

        $limit = (int) config('workspaces.commands.output_limit');

        return $workspace->commands()->create([
            'command' => $command,
            'exit_code' => $result->exitCode,
            'timed_out' => $result->timedOut,
            'duration_ms' => $result->durationMs,
            'output' => Str::limit($result->output, $limit),
            'error_output' => Str::limit($result->errorOutput, $limit),
        ]);
    }
}
