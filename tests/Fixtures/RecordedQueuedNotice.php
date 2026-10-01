<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A notification the framework sends from a job of its own.
 */
class RecordedQueuedNotice extends RecordedNotice implements ShouldQueue
{
    use Queueable;
}
