<?php

namespace TraceRecorder;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Testing\Fakes\BusFake;

/**
 * Stands in for a test's job fake (see Fakes): it tells the recorder of
 * each job the fake takes, then does what the fake does.
 *
 * A job for the queue is noted as queued. A job the app runs before or
 * right after it answers did not run, so what it does stays hidden, and
 * so does what a chain or a batch of jobs does.
 */
class SeenJobs extends BusFake
{
    public ?Recorder $traceRecorder = null;

    public function dispatch(...$arguments)
    {
        $command = $this->taken($arguments);

        if ($command !== null) {
            $command instanceof ShouldQueue ? $this->traceRecorder?->queued($command) : $this->traceRecorder?->hide('jobs');
        }

        return parent::dispatch(...$arguments);
    }

    public function dispatchToQueue(...$arguments)
    {
        $command = $this->taken($arguments);

        if ($command !== null) {
            $this->traceRecorder?->queued($command);
        }

        return parent::dispatchToQueue(...$arguments);
    }

    public function dispatchSync(...$arguments)
    {
        $this->taken($arguments) !== null && $this->traceRecorder?->hide('jobs');

        return parent::dispatchSync(...$arguments);
    }

    public function dispatchNow(...$arguments)
    {
        $this->taken($arguments) !== null && $this->traceRecorder?->hide('jobs');

        return parent::dispatchNow(...$arguments);
    }

    public function dispatchAfterResponse(...$arguments)
    {
        $this->taken($arguments) !== null && $this->traceRecorder?->hide('jobs');

        return parent::dispatchAfterResponse(...$arguments);
    }

    public function chain(...$arguments)
    {
        $this->traceRecorder?->hide('jobs');

        return parent::chain(...$arguments);
    }

    public function batch(...$arguments)
    {
        $this->traceRecorder?->hide('jobs');

        return parent::batch(...$arguments);
    }

    /**
     * Get the job the fake takes, or null when it passes the job on.
     *
     * @param  array<array-key, mixed>  $arguments
     */
    protected function taken(array $arguments): ?object
    {
        $command = $arguments[0] ?? $arguments['command'] ?? null;

        return is_object($command) && $this->shouldFakeJob($command) ? $command : null;
    }
}
