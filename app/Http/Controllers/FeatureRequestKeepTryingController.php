<?php

namespace App\Http\Controllers;

use App\Actions\Runs\KeepTryingRun;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class FeatureRequestKeepTryingController extends Controller
{
    /**
     * Have a stopped change keep working from where it stopped.
     */
    public function store(FeatureRequest $featureRequest, KeepTryingRun $keepTryingRun): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $keepTryingRun->handle($featureRequest);

        return to_route('projects.show', ['project' => $featureRequest->project, 'change' => $featureRequest->uuid]);
    }
}
