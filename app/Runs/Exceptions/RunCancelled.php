<?php

namespace App\Runs\Exceptions;

use App\Enums\RunStatus;
use App\Models\Run;
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

    /**
     * Stop before the next paid AI call once the owner has asked to
     * cancel. Reads only the status, so it is cheap before every call.
     *
     * @throws self
     */
    public static function throwIfCancelling(Run $run): void
    {
        if (Run::query()->whereKey($run->id)->value('status') === RunStatus::Cancelling) {
            throw self::forRun($run->id);
        }
    }
}
