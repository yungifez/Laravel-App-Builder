<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\WorkspaceStatus;
use Database\Factories\WorkspaceFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $driver
 * @property string|null $driver_id
 * @property string|null $baseline_commit The commit a run's change is measured against
 * @property WorkspaceStatus $status
 * @property string $image
 * @property float $cpus
 * @property int $memory_mb
 * @property int $pids
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $destroyed_at
 * @property Carbon|null $cleanup_failed_at When removing its environment last failed
 * @property string|null $cleanup_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['driver', 'driver_id', 'baseline_commit', 'status', 'image', 'cpus', 'memory_mb', 'pids', 'last_activity_at', 'expires_at', 'destroyed_at', 'cleanup_failed_at', 'cleanup_error'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WorkspaceStatus::class,
            'cpus' => 'float',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'destroyed_at' => 'datetime',
            'cleanup_failed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who owns the workspace.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the commands run in the workspace.
     *
     * @return HasMany<WorkspaceCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(WorkspaceCommand::class);
    }

    /**
     * Scope the query to ready workspaces that have been idle too long or are past their expiry.
     *
     * @param  Builder<self>  $query
     */
    public function scopeReapable(Builder $query, DateTimeInterface $idleSince, DateTimeInterface $now): void
    {
        $query->where('status', WorkspaceStatus::Ready)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query
                    ->where('last_activity_at', '<', $idleSince)
                    // The owner's own tool may work on a change in its own
                    // folder for longer than that, and its tries, preview
                    // and file tools need this copy. Its connection running
                    // out stops the change and lets the copy go.
                    ->whereNotExists(fn ($runs) => $runs->from('runs')
                        ->whereColumn('runs.workspace_id', 'workspaces.id')
                        ->where('runs.driver', 'worker')
                        ->where('runs.status', RunStatus::Implementing)))
                ->orWhere('expires_at', '<', $now));
    }
}
