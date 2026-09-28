<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\RequestLiveErrorFix;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LiveErrorFixController extends Controller
{
    /**
     * Ask for the problems the app ran into online to be fixed.
     */
    public function store(Request $request, Project $project, RequestLiveErrorFix $requestLiveErrorFix): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $fix = $requestLiveErrorFix->handle($project, $request->user());

        return to_route('projects.show', ['project' => $project, 'change' => $fix->uuid]);
    }
}
