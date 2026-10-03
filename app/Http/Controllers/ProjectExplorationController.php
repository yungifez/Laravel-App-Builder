<?php

namespace App\Http\Controllers;

use App\Actions\Context\RequestNotesDraft;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectExplorationController extends Controller
{
    /**
     * Explore the app and draft its notes, as the owner chose to after
     * reading what it costs.
     */
    public function store(Project $project, RequestNotesDraft $requestNotesDraft): RedirectResponse
    {
        Gate::authorize('update', $project);

        $requestNotesDraft->handle($project);

        return back();
    }
}
