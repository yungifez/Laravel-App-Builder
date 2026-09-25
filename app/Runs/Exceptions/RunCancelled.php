<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * The owner cancelled the run, so no further work may start.
 */
class RunCancelled extends RuntimeException
{
    /**
     * Create an exception for the given run.
     */
    public static function forRun(int $runId): self
    {
        return new self("Run [{$runId}] is being cancelled.");
    }
}
