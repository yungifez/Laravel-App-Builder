<?php

namespace App\Workspaces\Exceptions;

use RuntimeException;

class WorkspaceBusyException extends RuntimeException
{
    /**
     * Create an exception for an owner who has no free command slot.
     */
    public static function forOwner(int $ownerId): self
    {
        return new self("Owner [{$ownerId}] is already running the maximum number of workspace commands.");
    }
}
