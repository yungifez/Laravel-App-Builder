<?php

namespace App\Enums;

enum PreviewStatus: string
{
    case Starting = 'starting';
    case Ready = 'ready';
    case Failed = 'failed';
    case Stopped = 'stopped';

    /**
     * Determine if the preview is starting or running.
     */
    public function active(): bool
    {
        return in_array($this, [self::Starting, self::Ready], true);
    }
}
