<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\CheckDeployment;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DeploymentCheckController extends Controller
{
    /**
     * Check again that the newest version sent is online.
     */
    public function store(Project $project, CheckDeployment $checkDeployment): RedirectResponse
    {
        Gate::authorize('update', $project);

        $checkDeployment->handle($project);

        return back();
    }
}
