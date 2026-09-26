<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operators
    |--------------------------------------------------------------------------
    |
    | The email addresses that may open the operations screens, which show
    | every owner's changes. Comma separated. Nobody is an operator unless
    | listed here, and a listed address must be verified.
    |
    */

    'operators' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('OPERATIONS_OPERATORS', '')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Queue Workers
    |--------------------------------------------------------------------------
    |
    | The queues that must have a worker. A worker that has not looped for
    | "stale_seconds", and is not inside the time its current job may take,
    | is counted as silent. Workers write their heartbeat at most every
    | "write_seconds" while idle.
    |
    */

    'workers' => [
        'queues' => array_values(array_unique(array_filter([
            'default',
            env('BUILDER_PREVIEW_QUEUE'),
        ]))),
        'stale_seconds' => (int) env('OPERATIONS_WORKER_STALE_SECONDS', 60),
        'write_seconds' => 10,
        // A job waiting longer than this is a backlog to look at.
        'wait_seconds' => (int) env('OPERATIONS_QUEUE_WAIT_SECONDS', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Needs Attention
    |--------------------------------------------------------------------------
    |
    | A run that is not waiting on its owner and has logged nothing for
    | "stuck_minutes" is stuck. A lease counts as expired once it has been
    | gone for "lease_grace_seconds", which leaves time for runs:reconcile.
    | A workspace is overdue for cleanup "cleanup_grace_minutes" after the
    | reaper should have removed it, and orphaned when nothing has used it
    | for that long. Failures are counted over the last "window_days".
    |
    */

    'attention' => [
        'stuck_minutes' => (int) env('OPERATIONS_STUCK_MINUTES', 30),
        'lease_grace_seconds' => 120,
        'cleanup_grace_minutes' => 15,
        'window_days' => 7,
        // Rebuilds slower than this, from edit to screen, are listed.
        'slow_rebuild_seconds' => (int) env('OPERATIONS_SLOW_REBUILD_SECONDS', 30),
        'records' => 10,
    ],

];
