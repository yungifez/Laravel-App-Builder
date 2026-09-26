<?php

namespace App\Http\Controllers;

use App\Actions\Features\RetryFeatureRequest;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeatureRequestRetryController extends Controller
{
    /**
     * Ask for a stopped change again.
     */
    public function store(Request $request, FeatureRequest $featureRequest, RetryFeatureRequest $retryFeatureRequest): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $retry = $retryFeatureRequest->handle($featureRequest, $request->user());

        return to_route('projects.show', ['project' => $retry->project_id, 'change' => $retry->id]);
    }
}
