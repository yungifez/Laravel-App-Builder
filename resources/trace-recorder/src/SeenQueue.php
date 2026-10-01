<?php

namespace TraceRecorder;

use Illuminate\Support\Testing\Fakes\QueueFake;

/**
 * Stands in for a test's queue fake (see Fakes): it tells the recorder of
 * each job the fake takes, then does what the fake does. A job the fake
 * passes on to the queue is seen there.
 */
class SeenQueue extends QueueFake
{
    public ?Recorder $traceRecorder = null;

    public function push(...$arguments)
    {
        $job = $arguments[0] ?? $arguments['job'] ?? null;

        if ($job !== null && $this->shouldFakeJob($job)) {
            $this->traceRecorder?->queued($job);
        }

        return parent::push(...$arguments);
    }
}
