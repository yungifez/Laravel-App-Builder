<?php

namespace App\Actions\Billing;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * An operator gives a person a plan without payment, or takes it back. A
 * paid plan that is bigger still counts (MeasureUsage).
 */
class GrantPlan
{
    /**
     * Give the plan until the date, or for good without one. No plan takes
     * the grant back.
     */
    public function handle(User $user, ?string $plan, ?CarbonImmutable $until = null): User
    {
        $user->forceFill([
            'granted_plan' => $plan,
            'granted_plan_until' => $plan === null ? null : $until?->endOfDay(),
        ])->save();

        return $user;
    }
}
