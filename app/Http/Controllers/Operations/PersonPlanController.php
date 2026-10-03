<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Billing\GrantPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\GrantPlanRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PersonPlanController extends Controller
{
    /**
     * Give the person a plan without payment, or take it back.
     */
    public function update(GrantPlanRequest $request, User $user, GrantPlan $grantPlan): RedirectResponse
    {
        $grantPlan->handle(
            $user,
            $request->filled('plan') ? $request->string('plan')->value() : null,
            $request->filled('until') ? $request->date('until')?->toImmutable() : null,
        );

        return to_route('operations.people.show', $user);
    }
}
