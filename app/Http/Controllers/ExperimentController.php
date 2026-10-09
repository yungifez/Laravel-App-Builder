<?php

namespace App\Http\Controllers;

use App\Actions\Experiments\DiscardExperiment;
use App\Actions\Experiments\StartExperiment;
use App\Http\Requests\ExperimentStoreRequest;
use App\Models\Experiment;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ExperimentController extends Controller
{
    /**
     * Start trying an idea, apart from the main app.
     */
    public function store(ExperimentStoreRequest $request, Project $project, StartExperiment $startExperiment): RedirectResponse
    {
        $startExperiment->handle($project, $request->user(), $request->validated('name'));

        return to_route('projects.show', $project);
    }

    /**
     * Throw an idea away.
     */
    public function destroy(Experiment $experiment, DiscardExperiment $discardExperiment): RedirectResponse
    {
        Gate::authorize('update', $experiment->project);

        $discardExperiment->handle($experiment);

        return to_route('projects.show', $experiment->project);
    }
}
