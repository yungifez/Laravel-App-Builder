<?php

namespace App\Enums;

/**
 * Where a full check of the app's current version is. Passed and failed are
 * what the app's own checks said; errored means we could not finish them.
 */
enum HealthCheckStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';

    /**
     * Determine if the check is still in progress.
     */
    public function active(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
