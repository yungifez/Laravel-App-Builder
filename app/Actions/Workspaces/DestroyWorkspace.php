<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Str;
use Throwable;

class DestroyWorkspace
{
    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Stop the workspace and delete its environment.
     *
     * Callers often carry on when this fails, so the failure is kept on the
     * workspace: a box left running still costs money and holds code.
     *
     * @throws Throwable when the environment could not be removed.
     */
    public function handle(Workspace $workspace): void
    {
        if ($workspace->driver_id !== null && $workspace->status !== WorkspaceStatus::Destroyed) {
            try {
                $this->workspaces->driver($workspace->driver)->destroy($workspace->driver_id);
            } catch (Throwable $exception) {
                $workspace->update([
                    'cleanup_failed_at' => now(),
                    'cleanup_error' => Str::limit($exception->getMessage(), 2000),
                ]);

                throw $exception;
            }
        }

        $workspace->update([
            'status' => WorkspaceStatus::Destroyed,
            'destroyed_at' => now(),
        ]);
    }
}
