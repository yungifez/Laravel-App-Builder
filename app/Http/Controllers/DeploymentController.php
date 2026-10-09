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

        // The version the owner saw listed when they chose to publish.
        $seen = $request->validate(['seen' => ['nullable', 'string', 'regex:/^[0-9a-f]{40,64}$/']])['seen'] ?? null;

        // The owner said yes to losing information online, for that version.
        $publishProject->handle($project, $request->user(), $seen, $request->boolean('lose_data'));

        return back();
    }
}
