<?php

namespace App\Listeners;

use App\Enums\StopReason;
use App\Runs\AiAttempts;
use App\Runs\Exceptions\ProvidersUnavailable;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Exceptions\FailoverableException;

class RecordFailedOverProvider
{
    public function __construct(private AiAttempts $attempts) {}

    /**
     * Keep why a provider turned the request away before the SDK moved on
     * to the next one: the SDK only throws the last provider's error, and
     * an empty account is the one we must act on.
     */
    public function handle(AgentFailedOver $event): void
    {
        $stop = ProvidersUnavailable::because($event->exception);

        if ($stop->reason() === StopReason::OutOfCredit) {
            $this->attempts->outOfCredit($event->invocationId);
        }

        $this->record($stop, $event->provider->name());
    }

    /**
     * Keep why the last provider turned the request away too, so each
     * provider tried has its own reason. An error the SDK does not fail
     * over on is recorded where the run stops.
     */
    public function handleFailed(AgentFailed $event): void
    {
        if ($event->exception instanceof FailoverableException) {
            $this->record(ProvidersUnavailable::because($event->exception), $event->prompt->provider->name());
        }
    }

    /**
     * Forget what an earlier call's providers said once a new call starts.
     */
    public function handlePrompting(PromptingAgent $event): void
    {
        $this->attempts->started($event->invocationId);
    }

    /**
     * Record what a provider said on the run being asked for, if any. A
     * call made for no run, such as a check by operators, keeps nothing.
     */
    protected function record(ProvidersUnavailable $stop, string $provider): void
    {
        $this->attempts->run()?->recordEvent('ai_service_error', [
            'reason' => $stop->reason()->value,
            'provider' => $provider,
            ...(array) $stop->serviceError(),
        ]);
    }
}
