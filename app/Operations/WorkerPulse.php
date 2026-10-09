<?php

namespace App\Operations;

use App\Models\WorkerHeartbeat;
use Illuminate\Contracts\Queue\Job;

/**
 * This process's heartbeat as a queue worker. Only a process that has run
 * the worker loop writes one, so a job run inline (the sync queue, or a
 * test) never pretends to be a worker.
 */
class WorkerPulse
{
    protected ?string $connection = null;

    protected ?string $queues = null;

    protected ?float $lastWrite = null;

    /**
     * Note that the worker looped: it is idle and alive. Writes are spaced
     * out, since an idle worker loops every few seconds.
     */
    public function looped(string $connection, string $queues): void
    {
        $changed = $connection !== $this->connection || $queues !== $this->queues;

        $this->connection = $connection;
        $this->queues = $queues;

        if (! $changed && $this->lastWrite !== null && microtime(true) - $this->lastWrite < (int) config('operations.workers.write_seconds')) {
            return;
        }

        $this->write(['job' => null, 'job_started_at' => null, 'job_timeout' => null]);
    }

    /**
     * Note the job the worker started.
     */
    public function started(Job $job): void
    {
        if ($this->queues === null) {
            return;
        }

        $this->write([
            'job' => $job->resolveName(),
            'job_started_at' => now(),
            'job_timeout' => $job->timeout(),
        ]);
    }

    /**
     * Note that the worker finished its job, however it ended.
     */
    public function finished(): void
    {
        if ($this->queues === null) {
            return;
        }

        $this->write(['job' => null, 'job_started_at' => null, 'job_timeout' => null]);
    }

    /**
     * Remove the heartbeat of a worker that is shutting down on purpose.
     */
    public function stopped(): void
    {
        if ($this->queues === null) {
            return;
        }

        WorkerHeartbeat::query()->whereKey(self::name())->delete();
        $this->queues = null;
    }

    /**
     * Name this worker process.
     */
    public static function name(): string
    {
        return gethostname().':'.getmypid();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function write(array $attributes): void
    {
        $this->lastWrite = microtime(true);

        // A heartbeat must never stop the worker, even with the database down.
        rescue(fn () => WorkerHeartbeat::query()->updateOrCreate(['worker' => self::name()], [
            'connection' => (string) $this->connection,
            'queues' => (string) $this->queues,
            'last_seen_at' => now(),
            ...$attributes,
        ]), report: false);
    }
}
