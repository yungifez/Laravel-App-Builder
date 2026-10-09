<?php

namespace App\Http\Controllers;

use App\Actions\VisualEditing\DiscardDesignEdits;
use App\Actions\VisualEditing\KeepDesignEdits;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DesignEditsController extends Controller
{
    /**
     * Check the design edits that wait on the app, so they can join it.
     */
    public function store(Project $project, KeepDesignEdits $keepDesignEdits): RedirectResponse
    {
        Gate::authorize('update', $project);

        $keepDesignEdits->handle($project);

        return back();
    }

    /**
     * Throw away the design edits that wait on the app.
     */
    public function destroy(Project $project, DiscardDesignEdits $discardDesignEdits): RedirectResponse
    {
        Gate::authorize('update', $project);

        $discardDesignEdits->handle($project);

        return back();
    }
}
