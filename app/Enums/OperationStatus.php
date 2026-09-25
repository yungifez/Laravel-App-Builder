<?php

namespace App\Enums;

enum OperationStatus: string
{
    /** Recorded before the tool ran; its outcome is not known yet. */
    case Pending = 'pending';

    case Succeeded = 'succeeded';

    /** The tool ran and reported an error. */
    case Failed = 'failed';

    /** The server refused the call before running the tool. */
    case Rejected = 'rejected';

    /**
     * Determine if the operation has a final, replayable outcome.
     */
    public function finished(): bool
    {
        return $this !== self::Pending;
    }
}
