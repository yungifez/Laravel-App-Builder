<?php

namespace App\Actions\Billing;

use App\Actions\Operations\SummarizeSpend;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class MeasureUsage
{
    public function __construct(protected SummarizeSpend $summarizeSpend) {}

    /**
     * Say how much of this month's included AI use an owner has used. The
     * month starts on the day they subscribed, or signed up without a
     * subscription. Operators run the platform, so their use has no limit.
     *
     * @return array{plan: string, used_usd: float, allowance_usd: float, percent: int, started_at: CarbonImmutable, resets_at: CarbonImmutable, reached: bool, unlimited: bool}
     */
    public function handle(User $owner): array
    {
        $plan = $this->plan($owner);
        $startedAt = $this->monthStart($owner);
        $allowance = (float) config("billing.plans.{$plan}.monthly_usd");
        $used = $this->summarizeSpend->handle($startedAt, $owner)['total_usd'];
        $unlimited = Gate::forUser($owner)->allows('viewOperations');

        return [
            'plan' => $plan,
            'used_usd' => $used,
            'allowance_usd' => $allowance,
            'percent' => $allowance > 0 ? (int) min(100, floor($used / $allowance * 100)) : 100,
            'started_at' => $startedAt,
            'resets_at' => $startedAt->addMonthNoOverflow(),
            'reached' => ! $unlimited && $used >= $allowance,
            'unlimited' => $unlimited,
        ];
    }

    /**
     * Get the plan an owner is on: the bigger of the one whose Stripe price
     * their valid subscription has and the one an operator gave them, else
     * the first plan.
     */
    public function plan(User $owner): string
    {
        /** @var array<string, array{stripe_price: string|null}> $plans */
        $plans = config('billing.plans');
        $keys = array_keys($plans);
        $on = [0];
        $subscription = $owner->subscription();

        if ($subscription?->valid()) {
            foreach ($plans as $key => $plan) {
                if ($plan['stripe_price'] !== null && $subscription->hasPrice($plan['stripe_price'])) {
                    $on[] = (int) array_search($key, $keys, true);
                }
            }
        }

        if ($this->granted($owner) !== null) {
            $on[] = (int) array_search($this->granted($owner), $keys, true);
        }

        return (string) $keys[max($on)];
    }

    /**
     * Get the plan an operator gave the owner, while it lasts.
     */
    public function granted(User $owner): ?string
    {
        $plan = $owner->granted_plan;
        $until = $owner->granted_plan_until;

        return $plan !== null && array_key_exists($plan, (array) config('billing.plans')) && ($until === null || $until->isFuture())
            ? $plan
            : null;
    }

    /**
     * Get the start of the owner's current month of use.
     */
    protected function monthStart(User $owner): CarbonImmutable
    {
        $subscription = $owner->subscription();
        $anchor = CarbonImmutable::parse(($subscription?->valid() ? $subscription->created_at : $owner->created_at) ?? now());
        $start = $anchor->addMonthsNoOverflow((int) floor($anchor->diffInMonths(now())));

        return $start->isFuture() ? $start->subMonthNoOverflow() : $start;
    }
}
