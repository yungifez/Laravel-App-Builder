<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class DashboardController extends Controller
{
    /**
     * Send the owner to their apps. The starter kit signs people in to
     * "dashboard", and an owner's home is the list of their apps.
     */
    public function __invoke(): RedirectResponse
    {
        return to_route('projects.index');
    }
}
