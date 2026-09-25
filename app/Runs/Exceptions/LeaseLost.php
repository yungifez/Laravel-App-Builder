<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * The caller's lease expired or another worker took the run over, so the
 * caller must stop writing to it.
 */
class LeaseLost extends RuntimeException
{
    /**
     * Create an exception for a stale fencing token.
     */
    public static function forRun(int $runId, int $fencingToken): self
    {
        return new self("The lease with fencing token [{$fencingToken}] no longer holds run [{$runId}].");
    }
}
