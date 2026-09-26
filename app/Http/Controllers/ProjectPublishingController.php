<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectPublishingUpdateRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ProjectPublishingController extends Controller
{
    /**
     * Choose where the project is published: the repository and branch the
     * hosting platform deploys from.
     */
    public function update(ProjectPublishingUpdateRequest $request, Project $project): RedirectResponse
    {
        $project->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. You can publish now.')]);

        return back();
    }
}
