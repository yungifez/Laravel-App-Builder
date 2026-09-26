<?php

namespace App\Http\Controllers;

use App\Actions\Context\DiscardNotesDraft;
use App\Actions\Context\KeepNotesDraft;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ProjectNotesDraftController extends Controller
{
    /**
     * Keep the drafted notes: write them into the app.
     */
    public function store(Request $request, Project $project, KeepNotesDraft $keepNotesDraft): RedirectResponse
    {
        Gate::authorize('update', $project);

        $keepNotesDraft->handle($project, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kept. Change anything that is wrong below.')]);

        return back();
    }

    /**
     * Throw the drafted notes away.
     */
    public function destroy(Project $project, DiscardNotesDraft $discardNotesDraft): RedirectResponse
    {
        Gate::authorize('update', $project);

        $discardNotesDraft->handle($project);

        return back();
    }
}
