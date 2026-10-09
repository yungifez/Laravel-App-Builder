<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ListPlans;
use App\Actions\Billing\MeasureUsage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    /**
     * Show the plans, and which one a signed-in owner is on.
     */
    public function __invoke(Request $request, ListPlans $listPlans, MeasureUsage $measureUsage): Response
    {
        return Inertia::render('public/Pricing', [
            'plans' => $listPlans->handle(),
            'currentPlan' => $request->user() !== null ? $measureUsage->plan($request->user()) : null,
        ]);
    }
}
