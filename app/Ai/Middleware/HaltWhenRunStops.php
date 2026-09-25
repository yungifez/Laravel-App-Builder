<?php

namespace App\Ai\Middleware;

use App\Runs\ToolSession;
use Closure;
use Laravel\Ai\PendingStep;

/**
 * Ends the model loop before its next step once the run's tool session has
 * stopped (lost lease, cancellation or exhausted budget), so no further model
 * calls are paid for.
 */
class HaltWhenRunStops
{
    public function __construct(protected ToolSession $session) {}

    /**
     * Handle the pending generation step.
     */
    public function handle(PendingStep $step, Closure $next): mixed
    {
        $this->session->throwIfHalted();

        return $next($step);
    }
}
