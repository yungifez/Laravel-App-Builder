<?php

namespace App\Http\Controllers;

use App\Actions\Projects\ConnectOwnTool;
use App\Actions\Projects\DisconnectOwnTool;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectOwnToolController extends Controller
{
    /**
     * Let the owner's own Claude Code or Codex write the app's changes.
     */
    public function store(Project $project, ConnectOwnTool $connectOwnTool): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        // Shown once: only the token's hash is kept.
        Inertia::flash('own_tool', $connectOwnTool->handle($project));

        return back();
    }

    /**
     * Let us write the app's changes again.
     */
    public function destroy(Project $project, DisconnectOwnTool $disconnectOwnTool): RedirectResponse
    {
        Gate::authorize('requestFeatures', $project);

        $disconnectOwnTool->handle($project);

        return back();
    }
}
