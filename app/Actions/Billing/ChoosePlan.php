<?php

namespace App\Actions\Billing;

use App\Models\User;

class ChoosePlan
{
    /**
     * Move an owner to a paid plan. A subscriber's plan is swapped at once,
     * with Stripe settling the difference; anyone else pays through Stripe
     * Checkout first, so the address to send them to is returned.
     */
    public function handle(User $owner, string $price): ?string
    {
        $subscription = $owner->subscription();

        if ($subscription?->valid()) {
            $subscription->swap($price);

            return null;
        }

        return $owner->newSubscription('default', $price)->checkout([
            'success_url' => route('billing.edit', ['subscribed' => 1]),
            'cancel_url' => route('pricing'),
        ])->asStripeCheckoutSession()->url;
    }
}
