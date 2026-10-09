<?php

namespace App\Http\Controllers;

use App\Projects\DesignDirection;
use App\Projects\Starter;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * Show the home page, with the same ready-made ideas and looks a new
     * app can start from, so what a visitor sees there is what they get.
     */
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'designs' => array_map(fn (DesignDirection $design) => $design->preview(), DesignDirection::all()),
            'starters' => array_map(fn (Starter $starter) => $starter->toArray(), Starter::all()),
        ]);
    }
}
