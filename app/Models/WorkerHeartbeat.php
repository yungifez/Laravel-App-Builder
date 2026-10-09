<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * The last sign of life from one queue worker process. A worker writes it as
 * it loops and when it starts and ends a job, so a silent queue is visible
 * instead of only felt as slowness.
 *
 * @property string $worker Host and process ID
 * @property string $connection
 * @property string $queues The queues it serves, comma separated, in order
 * @property CarbonImmutable $last_seen_at
 * @property string|null $job The job it is running
 * @property CarbonImmutable|null $job_started_at
 * @property int|null $job_timeout Seconds the job may run
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['worker', 'connection', 'queues', 'last_seen_at', 'job', 'job_started_at', 'job_timeout'])]
class WorkerHeartbeat extends Model
{
    use Prunable;

    protected $primaryKey = 'worker';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'job_started_at' => 'datetime',
            'job_timeout' => 'integer',
        ];
    }

    /**
     * Get the queues this worker serves.
     *
     * @return list<string>
     */
    public function queueNames(): array
    {
        return array_values(array_filter(explode(',', $this->queues)));
    }

    /**
     * Determine if the worker showed life recently enough: it looped within
     * the stale window, or it is inside the time its current job may take.
     */
    public function alive(CarbonImmutable $now): bool
    {
        $stale = (int) config('operations.workers.stale_seconds');

        if ($this->last_seen_at->diffInSeconds($now) <= $stale) {
            return true;
        }

        return $this->job_started_at !== null
            && $this->job_started_at->diffInSeconds($now) <= ($this->job_timeout ?? 0) + $stale;
    }

    /**
     * Forget workers not heard from in a day.
     *
     * @return Builder<WorkerHeartbeat>
     */
    public function prunable(): Builder
    {
        return self::query()->where('last_seen_at', '<', now()->subDay());
    }
}
