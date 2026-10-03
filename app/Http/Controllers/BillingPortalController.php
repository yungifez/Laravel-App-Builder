<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class BillingPortalController extends Controller
{
    /**
     * Send a subscriber to Stripe to change their card, see invoices or
     * cancel.
     */
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()->hasStripeId(), 404);

        return Inertia::location($request->user()->billingPortalUrl(route('billing.edit')));
    }
}
