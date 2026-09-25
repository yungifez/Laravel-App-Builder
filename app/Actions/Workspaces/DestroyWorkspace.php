<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;

class DestroyWorkspace
{
    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Stop the workspace and delete its environment.
     */
    public function handle(Workspace $workspace): void
    {
        if ($workspace->driver_id !== null && $workspace->status !== WorkspaceStatus::Destroyed) {
            $this->workspaces->driver($workspace->driver)->destroy($workspace->driver_id);
        }

        $workspace->update([
            'status' => WorkspaceStatus::Destroyed,
            'destroyed_at' => now(),
        ]);
    }
}
