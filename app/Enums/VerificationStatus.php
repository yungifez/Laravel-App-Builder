<?php

namespace App\Enums;

enum VerificationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    case Errored = 'errored';

    /**
     * Determine if the verification has finished.
     */
    public function finished(): bool
    {
        return in_array($this, [self::Passed, self::Failed, self::Errored], true);
    }
}
