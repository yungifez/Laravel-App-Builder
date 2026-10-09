<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\RequestCheckFix;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CheckFixController extends Controller
{
    /**
     * Ask for the checks that stopped the app going online to be fixed.
     */
    public function store(Request $request, Project $project, RequestCheckFix $requestCheckFix): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $fix = $requestCheckFix->handle($project, $request->user());

        return to_route('projects.show', ['project' => $project, 'change' => $fix->uuid]);
    }
}
