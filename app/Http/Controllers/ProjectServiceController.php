<?php

namespace App\Http\Controllers;

use App\Actions\Projects\ConnectService;
use App\Http\Requests\ProjectServiceStoreRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectServiceController extends Controller
{
    /**
     * Connect the app to an outside service with the keys the owner pasted,
     * and take them to the change that puts it to use.
     */
    public function store(ProjectServiceStoreRequest $request, Project $project, string $service, ConnectService $connectService): RedirectResponse
    {
        $featureRequest = $connectService->handle($project, $request->user(), $service, $request->keys());

        if ($featureRequest === null) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. Your app uses the new keys from now on.')]);

            return back();
        }

        return to_route('projects.show', ['project' => $project, 'change' => $featureRequest->id]);
    }
}
