<?php

namespace App\Runs\Exceptions;

use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;

/**
 * No configured AI provider could serve the task, so the run stops for the
 * owner rather than failing.
 */
class ProvidersUnavailable extends RuntimeException
{
    /**
     * Stop because every AI service turned the request away, saying why in
     * words the owner can act on. Nothing in the app changed.
     */
    public static function because(FailoverableException $exception): self
    {
        // Our AI service turning the request away is our fault, not the
        // owner's, so the owner is told so.
        $reason = match (true) {
            $exception instanceof RateLimitedException => __('This is our fault: the AI service we use is turning requests away because we sent too many.'),
            $exception instanceof InsufficientCreditsException => __('This is our fault: our account with the AI service we use has run out of credit.'),
            $exception instanceof ProviderOverloadedException => __('This is our fault: the AI service we use is too busy right now.'),
            default => __('This is our fault: we could not reach the AI service we use.'),
        };

        return new self($reason.' '.__('Nothing in your app changed. Try again in a few minutes.'), previous: $exception);
    }
}
