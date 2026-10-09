<?php

namespace App\Http\Controllers;

use App\Actions\Features\DismissFeatureRequest;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class FeatureRequestDismissalController extends Controller
{
    /**
     * Mark an ask as no longer needed.
     */
    public function store(FeatureRequest $featureRequest, DismissFeatureRequest $dismissFeatureRequest): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $dismissFeatureRequest->handle($featureRequest);

        return back();
    }

    /**
     * Bring a dismissed ask back.
     */
    public function destroy(FeatureRequest $featureRequest, DismissFeatureRequest $dismissFeatureRequest): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $dismissFeatureRequest->restore($featureRequest);

        return back();
    }
}
