<?php

namespace App\Ai\Middleware;

use App\Support\Secrets;
use Closure;
use Laravel\Ai\Gateway\StepResult;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\PendingStep;

/**
 * Keep live keys out of what an agent sends to its model. The request,
 * notes and files it quotes come from the owner's app, and a key in them
 * would leave with the call.
 */
class RedactSecrets
{
    /**
     * Handle the pending generation step.
     *
     * @param  Closure(PendingStep): StepResult  $next
     */
    public function handle(PendingStep $step, Closure $next): StepResult
    {
        $messages = array_map(function (Message $message) {
            if ($message->content === null || ! Secrets::found($message->content)) {
                return $message;
            }

            $message = clone $message;
            $message->content = Secrets::redact($message->content);

            return $message;
        }, $step->messages);

        $instructions = $step->instructions === null ? null : Secrets::redact($step->instructions);

        return $next($step->withInstructions($instructions)->withMessages($messages));
    }
}
