<?php

namespace App\Runs\Exceptions;

use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Throwable;

/**
 * No configured AI provider could serve the task, so the run stops for the
 * owner rather than failing.
 */
class ProvidersUnavailable extends RuntimeException
{
    /**
     * @param  string  $reason  Why the run stops: providers_unavailable for a busy or unreachable service, out_of_credit when our account ran out of credit, request_refused when the service would not accept how we asked. Only we can put the last two right.
     */
    public function __construct(string $message, private readonly string $reason = 'providers_unavailable', ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Get why the run stops, as its stop reason.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * Determine if what an AI service said means our account is out of
     * credit, rather than busy or turning requests away for a while.
     */
    public static function saysOutOfCredit(?string $kind, ?string $error): bool
    {
        return $kind === 'billing_error' || preg_match('/credit balance|quota|billing/i', (string) $error) === 1;
    }

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

        // When credit comes back is not known, so no wait is promised.
        $credit = $exception instanceof InsufficientCreditsException;

        return new self($reason.' '.($credit ? __('Nothing in your app changed. Try again later.') : __('Nothing in your app changed. Try again in a few minutes.')), $credit ? 'out_of_credit' : 'providers_unavailable', $exception);
    }

    /**
     * Stop because the AI service answered with an error the SDK does not
     * name. A refusal of the request itself (a bad key, or a request it
     * cannot accept) is our mistake and comes back the same on every try,
     * so it is never retried and we are told. A busy or failing service
     * is worth another try in a few minutes.
     */
    public static function fromResponse(RequestException $exception): self
    {
        $status = $exception->response->status();

        if ($status >= 400 && $status < 500 && ! in_array($status, [408, 429], true)) {
            return new self(__('This is our fault: the AI service we use could not accept how we asked it. We have been told. Nothing in your app changed. Try again later.'), 'request_refused', $exception);
        }

        return new self(__('This is our fault: we could not reach the AI service we use. Nothing in your app changed. Try again in a few minutes.'), 'providers_unavailable', $exception);
    }

    /**
     * Get what the AI service said, for operators: its status and error
     * type only, never our prompt or the request it echoes back.
     *
     * @return array{status: int, type: string|null}|null
     */
    public function serviceError(): ?array
    {
        $previous = $this->getPrevious();

        if (! $previous instanceof RequestException) {
            return null;
        }

        $type = $previous->response->json('error.type');

        return ['status' => $previous->response->status(), 'type' => is_string($type) ? $type : null];
    }
}
