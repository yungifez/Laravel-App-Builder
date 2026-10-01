<?php

namespace App\Http\Controllers;

use App\Actions\Previews\ShareApp;
use App\Actions\Previews\StopSharingApp;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectShareController extends Controller
{
    /**
     * Make or renew the link that lets others try the app.
     */
    public function store(Project $project, ShareApp $shareApp): RedirectResponse
    {
        Gate::authorize('update', $project);

        $shareApp->handle($project);

        return back();
    }

    /**
     * End the link, so no one else can try the app.
     */
    public function destroy(Project $project, StopSharingApp $stopSharingApp): RedirectResponse
    {
        Gate::authorize('update', $project);

        $stopSharingApp->handle($project);

        return back();
    }
}
