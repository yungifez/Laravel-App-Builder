<?php

namespace App\Http\Controllers\Operations;

use App\Actions\Operations\MeasureBusiness;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NumbersController extends Controller
{
    /**
     * Show how the business is doing over the last week, month or quarter.
     */
    public function __invoke(Request $request, MeasureBusiness $measureBusiness): Response
    {
        $days = in_array((int) $request->query('days'), [7, 30, 90], true) ? (int) $request->query('days') : 30;

        return Inertia::render('operations/Numbers', $measureBusiness->handle($days));
    }
}
