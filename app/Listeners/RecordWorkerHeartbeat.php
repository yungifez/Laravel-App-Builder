<?php

namespace App\Listeners;

use App\Operations\WorkerPulse;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;

/**
 * Keep each queue worker's heartbeat. On 2026-09-26 the workers were down
 * for about 13 minutes and nothing showed it; the owner only noticed that
 * changes were slow.
 */
class RecordWorkerHeartbeat
{
    public function __construct(private WorkerPulse $pulse) {}

    public function handleLooping(Looping $event): void
    {
        $this->pulse->looped($event->connectionName, $event->queue);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        $this->pulse->started($event->job);
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $this->pulse->finished();
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->pulse->finished();
    }

    public function handleJobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->pulse->finished();
    }

    public function handleWorkerStopping(WorkerStopping $event): void
    {
        $this->pulse->stopped();
    }
}
