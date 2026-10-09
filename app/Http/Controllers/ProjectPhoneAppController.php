<?php

namespace App\Http\Controllers;

use App\Actions\Projects\AddPhoneApp;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectPhoneAppController extends Controller
{
    /**
     * Start a phone app that talks to the owner's app, and open it.
     */
    public function store(Project $project, AddPhoneApp $addPhoneApp): RedirectResponse
    {
        Gate::authorize('update', $project);

        return to_route('projects.show', $addPhoneApp->handle($project));
    }
}
