<?php

namespace TraceRecorder;

use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Throwable;

/**
 * The sync queue, with one change: it asks the recorder before it runs a
 * job. The recorder holds back the one job a run is about, so that job
 * runs after the response, the way it does on a real queue. This queue is
 * put in place only for such a run.
 */
class LaterQueue extends SyncQueue
{
    public function __construct(protected Recorder $recorder, $dispatchAfterCommit = false)
    {
        parent::__construct($dispatchAfterCommit);
    }

    /**
     * Make the connector that gives the app this queue as its sync queue.
     */
    public static function connector(Recorder $recorder): ConnectorInterface
    {
        return new class($recorder) implements ConnectorInterface
        {
            public function __construct(private Recorder $recorder) {}

            public function connect(array $config)
            {
                return new LaterQueue($this->recorder, $config['after_commit'] ?? null);
            }
        };
    }

    /**
     * Run the job now, unless the recorder holds it back.
     */
    protected function executeJob($job, $data = '', $queue = null)
    {
        $run = fn () => parent::executeJob($job, $data, $queue);

        try {
            $held = $this->recorder->hold($job, fn () => $this->resolveJob($this->createPayload($job, $queue, $data), $queue), $run);
        } catch (Throwable) {
            $held = false;
        }

        return $held ? 0 : $run();
    }
}
