<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\RestoreDeployment;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeploymentRestorationController extends Controller
{
    /**
     * Put an earlier version of the app back online.
     */
    public function store(Request $request, Project $project, Deployment $deployment, RestoreDeployment $restoreDeployment): RedirectResponse
    {
        Gate::authorize('update', $project);

        $restoreDeployment->handle($project, $request->user(), $deployment);

        return back();
    }
}
