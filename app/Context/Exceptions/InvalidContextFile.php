<?php

namespace App\Context\Exceptions;

use RuntimeException;

class InvalidContextFile extends RuntimeException
{
    /**
     * Create an exception for a context file that cannot be read.
     */
    public static function at(string $path, string $reason): self
    {
        return new self(__(':path: :reason', ['path' => $path, 'reason' => $reason]));
    }
}
