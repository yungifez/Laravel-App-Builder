<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectEditorController extends Controller
{
    /**
     * Open the project's workspace with the design panel showing, where the
     * owner points at a part of the app and changes how it looks.
     */
    public function show(Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);

        return to_route('projects.show', ['project' => $project, 'design' => 1]);
    }
}
