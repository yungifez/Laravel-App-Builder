<?php

namespace App\Enums;

/**
 * Where a command sent to a box runner is. A command is claimed once, so two
 * runners never run it twice.
 */
enum BoxCommandStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Finished = 'finished';
    // The runner never answered, so nobody knows how it ended.
    case Lost = 'lost';

    /**
     * Determine if the command has ended, one way or another.
     */
    public function ended(): bool
    {
        return in_array($this, [self::Finished, self::Lost], true);
    }
}
