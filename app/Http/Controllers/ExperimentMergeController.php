<?php

namespace App\Http\Controllers;

use App\Actions\Experiments\MergeExperiment;
use App\Models\Experiment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ExperimentMergeController extends Controller
{
    /**
     * Use an idea in the app.
     */
    public function store(Request $request, Experiment $experiment, MergeExperiment $mergeExperiment): RedirectResponse
    {
        Gate::authorize('update', $experiment->project);

        $mergeExperiment->handle($experiment, $request->user());

        return to_route('projects.show', $experiment->project);
    }
}
