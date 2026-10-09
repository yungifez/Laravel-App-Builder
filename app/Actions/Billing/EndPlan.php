<?php

namespace App\Actions\Billing;

use App\Models\User;
use Laravel\Cashier\Subscription;

/**
 * Stop a person's paid plan at once, when they delete their account, so
 * they are never charged for an account that is gone.
 */
class EndPlan
{
    public function handle(User $user): void
    {
        $subscription = $user->subscription();

        if ($subscription === null || $subscription->ended()) {
            return;
        }

        $subscription->cancelNow();
        $this->refund($subscription);
    }

    /**
     * Stripe does not refund a cancelled plan by itself. Whether the unused
     * part of the month comes back is the business's decision, not made
     * yet (2026-10-03), so nothing is refunded here for now.
     */
    protected function refund(Subscription $subscription): void {}
}
