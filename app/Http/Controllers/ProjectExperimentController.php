<?php

namespace App\Http\Controllers;

use App\Actions\Experiments\SwitchExperiment;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectExperimentController extends Controller
{
    /**
     * Work in another idea, or in the main app.
     */
    public function update(Request $request, Project $project, SwitchExperiment $switchExperiment): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate(['experiment' => ['nullable', 'integer']]);
        $experiment = isset($validated['experiment']) ? $project->experiments()->whereKey($validated['experiment'])->firstOrFail() : null;

        $switchExperiment->handle($project, $experiment);

        return to_route('projects.show', $project);
    }
}
