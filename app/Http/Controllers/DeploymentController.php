<?php

namespace App\Http\Controllers;

use App\Actions\Publishing\PublishProject;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeploymentController extends Controller
{
    /**
     * Publish the project as it is now.
     */
    public function store(Request $request, Project $project, PublishProject $publishProject): RedirectResponse
    {
        Gate::authorize('update', $project);

        $publishProject->handle($project, $request->user());

        return back();
    }
}
