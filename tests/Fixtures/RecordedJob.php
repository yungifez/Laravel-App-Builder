<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A job of the app RecordedApp stands in for.
 */
class RecordedJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        //
    }
}
