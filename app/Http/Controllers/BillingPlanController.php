<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ChoosePlan;
use App\Actions\Billing\ListPlans;
use App\Http\Requests\ChoosePlanRequest;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BillingPlanController extends Controller
{
    /**
     * Move the owner to the paid plan they chose.
     */
    public function store(ChoosePlanRequest $request, ListPlans $listPlans, ChoosePlan $choosePlan): Response
    {
        $url = $choosePlan->handle($request->user(), (string) $listPlans->stripePrice($request->string('plan')->toString()));

        if ($url !== null) {
            return Inertia::location($url);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your plan changed.')]);

        return to_route('billing.edit');
    }
}
