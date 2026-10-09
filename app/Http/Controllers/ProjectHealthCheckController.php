<?php

namespace App\Http\Controllers;

use App\Actions\Context\RequestHealthCheck;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectHealthCheckController extends Controller
{
    /**
     * Run the app's full checks and package lookups on its current version,
     * with the quick check of its notes.
     */
    public function store(Project $project, RequestHealthCheck $requestHealthCheck): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $requestHealthCheck->handle($project);

        return back();
    }
}
