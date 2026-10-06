<?php

namespace App\Publishing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The host did not take a release. The message is written for the owner;
 * what the host or Git said stays on the previous exception.
 */
class PublishingFailed extends RuntimeException
{
    /**
     * @param  bool  $settings  Whether the owner's publishing settings are what to put right
     */
    public function __construct(string $message, public readonly bool $settings = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
