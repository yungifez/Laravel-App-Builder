<?php

namespace App\Http\Controllers;

use App\Actions\Context\RequestHealthFix;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class HealthFixController extends Controller
{
    /**
     * Ask for what the full check of the app found to be fixed.
     */
    public function store(Request $request, Project $project, RequestHealthFix $requestHealthFix): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $fix = $requestHealthFix->handle($project, $request->user());

        return to_route('projects.show', ['project' => $project, 'change' => $fix->uuid]);
    }
}
