<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Show the internal landing screen.
     */
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'projectCount' => $request->user()->projects()->count(),
        ]);
    }
}
