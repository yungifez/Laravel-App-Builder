<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * Another worker holds an unexpired lease on the run.
 */
class RunLeaseHeld extends RuntimeException
{
    public function __construct(int $runId, public int $retryAfterSeconds)
    {
        parent::__construct("Run [{$runId}] is held by another worker.");
    }
}
