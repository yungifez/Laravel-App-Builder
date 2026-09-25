<?php

namespace App\Models;

use App\Enums\RunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to build a feature request's change in a workspace.
 *
 * A single worker writes to a run at a time. It holds a lease identified by
 * the fencing token; taking over an expired lease increments the token, so
 * the previous holder's late writes are refused.
 *
 * @property int $id
 * @property int $feature_request_id
 * @property int|null $workspace_id
 * @property string $driver
 * @property RunStatus $status
 * @property int $fencing_token
 * @property string|null $lease_owner
 * @property CarbonImmutable|null $lease_expires_at
 * @property int $workspace_revision
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['workspace_id', 'driver', 'status', 'fencing_token', 'lease_owner', 'lease_expires_at', 'workspace_revision', 'error', 'started_at', 'finished_at'])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'fencing_token' => 'integer',
            'workspace_revision' => 'integer',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Get the feature request the run builds.
     *
     * @return BelongsTo<FeatureRequest, $this>
     */
    public function featureRequest(): BelongsTo
    {
        return $this->belongsTo(FeatureRequest::class);
    }

    /**
     * Get the workspace the run builds in.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the run's events, in order.
     *
     * @return HasMany<RunEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RunEvent::class)->orderBy('sequence');
    }

    /**
     * Get the run's operation journal.
     *
     * @return HasMany<RunOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(RunOperation::class);
    }

    /**
     * Get the verifications started by the run.
     *
     * @return HasMany<Verification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /**
     * Determine if a worker holds an unexpired lease on the run.
     */
    public function hasActiveLease(): bool
    {
        return $this->lease_owner !== null
            && $this->lease_expires_at !== null
            && $this->lease_expires_at->isFuture();
    }

    /**
     * Push the lease's expiry out by the configured lease time.
     */
    public function extendLease(): void
    {
        $this->update(['lease_expires_at' => now()->addSeconds((int) config('builder.construction.lease_seconds'))]);
    }

    /**
     * Append an event to the run's log.
     *
     * Call this inside a transaction that holds the run's row lock, so the
     * sequence numbers stay gapless and ordered.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordEvent(string $type, array $data = []): RunEvent
    {
        $sequence = (int) RunEvent::query()->where('run_id', $this->id)->max('sequence') + 1;

        return $this->events()->create([
            'sequence' => $sequence,
            'type' => $type,
            'data' => $data,
        ]);
    }
}
