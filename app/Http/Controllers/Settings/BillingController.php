<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Billing\ListPlans;
use App\Actions\Billing\MeasureUsage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /**
     * Show the owner's plan and how much of this month's use is left.
     */
    public function edit(Request $request, ListPlans $listPlans, MeasureUsage $measureUsage): Response
    {
        $usage = $measureUsage->handle($request->user());

        return Inertia::render('settings/Billing', [
            'plans' => $listPlans->handle(),
            'currentPlan' => $usage['plan'],
            'usage' => [
                'percent' => $usage['percent'],
                'resetsOn' => $usage['resets_at']->isoFormat('D MMMM'),
                'unlimited' => $usage['unlimited'],
            ],
            'canManage' => $request->user()->hasStripeId(),
        ]);
    }
}
