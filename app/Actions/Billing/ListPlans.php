<?php

namespace App\Actions\Billing;

class ListPlans
{
    /**
     * List the plans as the pricing page shows them. How much more use a
     * plan has is measured against the first plan, so the page never
     * promises an amount it cannot keep. A paid plan is open once Stripe
     * and its price are set.
     *
     * @return list<array{key: string, name: string, price: int, times: int, open: bool}>
     */
    public function handle(): array
    {
        /** @var array<string, array{name: string, price: int, stripe_price: string|null, monthly_usd: float}> $plans */
        $plans = config('billing.plans');
        $base = max(0.01, (float) (array_values($plans)[0]['monthly_usd'] ?? 0));
        $stripe = filled(config('cashier.secret'));

        return array_map(fn (array $plan, string $key) => [
            'key' => $key,
            'name' => $plan['name'],
            'price' => $plan['price'],
            'times' => (int) round($plan['monthly_usd'] / $base),
            'open' => $plan['price'] === 0 || ($stripe && filled($plan['stripe_price'])),
        ], $plans, array_keys($plans));
    }

    /**
     * Get the Stripe price of a paid plan that is open for sign-up.
     */
    public function stripePrice(string $key): ?string
    {
        $plan = collect($this->handle())->firstWhere('key', $key);

        return $plan !== null && $plan['open'] && $plan['price'] > 0
            ? (string) config("billing.plans.{$key}.stripe_price")
            : null;
    }
}
