<?php

namespace App\Runs\Exceptions;

use App\Enums\StopReason;
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
     * @param  StopReason  $reason  Why the run stops: ProvidersUnavailable for a busy or unreachable service, OutOfCredit when our account ran out of credit, RequestRefused when the service would not accept how we asked. Only we can put the last two right.
     */
    public function __construct(string $message, private readonly StopReason $reason = StopReason::ProvidersUnavailable, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Get why the run stops, as its stop reason.
     */
    public function reason(): StopReason
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
        // Some services answer an empty account with "too many requests"
        // (429), so a rate limit whose body says so is out of credit too.
        $credit = $exception instanceof InsufficientCreditsException
            || ($exception instanceof RateLimitedException && self::answerSaysOutOfCredit($exception));

        // The operators' attention list shows every stop for credit, so it
        // says the same as the stop: "we have been told" is true.
        if ($credit) {
            return new self(StopReason::OutOfCredit->said(), StopReason::OutOfCredit, $exception);
        }

        // Our AI service turning the request away is our fault, not the
        // owner's, so the owner is told so.
        $reason = match (true) {
            $exception instanceof RateLimitedException => __('This is our fault: the AI service we use is turning requests away because we sent too many.'),
            $exception instanceof ProviderOverloadedException => __('This is our fault: the AI service we use is too busy right now.'),
            default => __('This is our fault: we could not reach the AI service we use.'),
        };

        return new self($reason.' '.__('Nothing in your app changed. Try again in a few minutes.'), StopReason::ProvidersUnavailable, $exception);
    }

    /**
     * Determine if the answer behind an SDK exception says our account is
     * out of credit. An answer that is missing or not JSON says nothing.
     */
    protected static function answerSaysOutOfCredit(Throwable $exception): bool
    {
        $previous = $exception->getPrevious();

        if (! $previous instanceof RequestException) {
            return false;
        }

        $type = $previous->response->json('error.type');
        $code = $previous->response->json('error.code');
        $message = $previous->response->json('error.message');

        return ($code === 'insufficient_quota' || $type === 'insufficient_quota')
            || self::saysOutOfCredit(is_string($type) ? $type : null, is_string($message) ? $message : null);
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
            return new self(__('This is our fault: the AI service we use could not accept how we asked it. We have been told. Nothing in your app changed. Try again later.'), StopReason::RequestRefused, $exception);
        }

        return new self(__('This is our fault: we could not reach the AI service we use. Nothing in your app changed. Try again in a few minutes.'), StopReason::ProvidersUnavailable, $exception);
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
