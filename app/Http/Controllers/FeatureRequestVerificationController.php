<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestVerification;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class FeatureRequestVerificationController extends Controller
{
    /**
     * Run the project's checks against the feature request's change.
     */
    public function store(FeatureRequest $featureRequest, RequestVerification $requestVerification): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $requestVerification->handle($featureRequest);

        return to_route('feature-requests.show', $featureRequest);
    }
}
