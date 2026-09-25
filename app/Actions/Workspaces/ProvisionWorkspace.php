<?php

namespace App\Actions\Workspaces;

use App\Enums\WorkspaceStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Workspaces\WorkspaceManager;
use App\Workspaces\WorkspaceSpec;
use Illuminate\Support\Str;
use Throwable;

class ProvisionWorkspace
{
    public function __construct(private WorkspaceManager $workspaces) {}

    /**
     * Start a workspace for the owner with the configured driver and size.
     *
     * @throws Throwable when the driver cannot start the workspace; the
     *                   workspace is then marked as failed.
     */
    public function handle(User $owner, ?string $driver = null): Workspace
    {
        $driver ??= $this->workspaces->getDefaultDriver();
        $spec = WorkspaceSpec::fromConfig('workspace-'.Str::lower((string) Str::ulid()), $this->workspaces->imageFor($driver));

        $workspace = $owner->workspaces()->create([
            'driver' => $driver,
            'status' => WorkspaceStatus::Provisioning,
            'image' => $spec->image,
            'cpus' => $spec->cpus,
            'memory_mb' => $spec->memoryMb,
            'pids' => $spec->pids,
        ]);

        try {
            $driverId = $this->workspaces->driver($driver)->create($spec);
        } catch (Throwable $exception) {
            $workspace->update(['status' => WorkspaceStatus::Failed]);

            throw $exception;
        }

        $workspace->update([
            'driver_id' => $driverId,
            'status' => WorkspaceStatus::Ready,
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes((int) config('workspaces.lifetime.max_minutes')),
        ]);

        return $workspace;
    }
}
