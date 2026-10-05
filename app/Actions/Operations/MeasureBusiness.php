<?php

namespace App\Actions\Operations;

use App\Actions\Projects\MeasureChanges;
use App\Models\FeatureRequest;
use App\Models\User;
use App\Models\VisualEdit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\SubscriptionItem;

/**
 * The numbers an operator watches to run the business: what comes in each
 * month, who joins, who builds, what the AI costs against it, and how
 * the changes went across every app.
 */
class MeasureBusiness
{
    /** Stripe counts these subscriptions as paying, or about to. */
    public const PAYING = ['active', 'trialing', 'past_due'];

    public function __construct(protected SummarizeSpend $summarizeSpend, protected MeasureChanges $measureChanges) {}

    /**
     * Measure the business over the last given number of days.
     *
     * @return array{days: int, revenue: array{monthly_usd: int, plans: list<array{key: string, name: string, price: int, paying: int, given: int}>}, people: array{total: int, joined: int, building: int, verified: int}, spend: array{total_usd: float, completeness: string}, changes: array{kept: int, cost_usd: float, unpriced_calls: int, input_tokens: int, output_tokens: int, cost_per_kept_change_usd: float|null, runs_verified: int, first_attempt_passed: int, first_attempt_unverified: int, reviewed: int, with_unexpected_changes: int, edits_without_model: int}, daily: list<array{date: string, joined: int, changes: int}>}
     */
    public function handle(int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay()->toImmutable();
        $plans = $this->plans();

        return [
            'days' => $days,
            'revenue' => [
                // Monthly prices as the pricing page shows them; Stripe holds
                // the exact amounts after discounts and taxes.
                'monthly_usd' => array_sum(array_map(fn (array $plan) => $plan['price'] * $plan['paying'], $plans)),
                'plans' => $plans,
            ],
            'people' => [
                'total' => User::query()->count(),
                'joined' => User::query()->where('created_at', '>=', $since)->count(),
                'verified' => User::query()->where('created_at', '>=', $since)->whereNotNull('email_verified_at')->count(),
                'building' => FeatureRequest::query()
                    ->where('feature_requests.created_at', '>=', $since)
                    ->join('projects', 'projects.id', '=', 'feature_requests.project_id')
                    ->distinct()
                    ->count('projects.user_id'),
            ],
            'spend' => array_intersect_key($this->summarizeSpend->handle($since), array_flip(['total_usd', 'completeness'])),
            // The changes asked for in the window, by the same measures each
            // owner sees for their own app.
            'changes' => $this->measureChanges->handle(
                FeatureRequest::query()->where('created_at', '>=', $since),
                VisualEdit::query()->where('created_at', '>=', $since),
            ),
            'daily' => $this->daily($since, $days),
        ];
    }

    /**
     * Count paying and given people on each plan. A person paying for a
     * plan is not also counted as given one.
     *
     * @return list<array{key: string, name: string, price: int, paying: int, given: int}>
     */
    protected function plans(): array
    {
        /** @var array<string, array{name: string, price: int, stripe_price: string|null}> $config */
        $config = config('billing.plans');

        $paying = SubscriptionItem::query()
            ->whereHas('subscription', fn ($subscriptions) => $subscriptions->whereIn('stripe_status', self::PAYING))
            ->whereIn('stripe_price', array_filter(array_column($config, 'stripe_price')))
            ->toBase()
            ->groupBy('stripe_price')
            ->selectRaw('stripe_price, count(distinct subscription_id) as paying')
            ->pluck('paying', 'stripe_price');

        $given = User::query()
            ->whereNotNull('granted_plan')
            ->where(fn ($users) => $users->whereNull('granted_plan_until')->orWhere('granted_plan_until', '>', now()))
            ->whereDoesntHave('subscriptions', fn ($subscriptions) => $subscriptions->whereIn('stripe_status', self::PAYING))
            ->toBase()
            ->groupBy('granted_plan')
            ->selectRaw('granted_plan, count(*) as given')
            ->pluck('given', 'granted_plan');

        return array_values(collect($config)
            ->slice(1)
            ->map(fn (array $plan, string $key) => [
                'key' => $key,
                'name' => $plan['name'],
                'price' => (int) $plan['price'],
                'paying' => $plan['stripe_price'] === null ? 0 : (int) ($paying[$plan['stripe_price']] ?? 0),
                'given' => (int) ($given[$key] ?? 0),
            ])
            ->all());
    }

    /**
     * Count sign-ups and new changes for each day, with empty days kept so
     * the bars line up.
     *
     * @return list<array{date: string, joined: int, changes: int}>
     */
    protected function daily(CarbonImmutable $since, int $days): array
    {
        $joined = User::query()->where('created_at', '>=', $since)->toBase()
            ->groupBy(DB::raw('created_at::date'))
            ->selectRaw('created_at::date as day, count(*) as count')
            ->pluck('count', 'day');

        $changes = FeatureRequest::query()->where('created_at', '>=', $since)->toBase()
            ->groupBy(DB::raw('created_at::date'))
            ->selectRaw('created_at::date as day, count(*) as count')
            ->pluck('count', 'day');

        return array_values(collect(range(0, $days - 1))
            ->map(fn (int $offset) => $since->addDays($offset)->toDateString())
            ->map(fn (string $date) => [
                'date' => $date,
                'joined' => (int) ($joined[$date] ?? 0),
                'changes' => (int) ($changes[$date] ?? 0),
            ])
            ->all());
    }
}
