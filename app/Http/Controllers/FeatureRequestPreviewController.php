<?php

namespace App\Http\Controllers;

use App\Actions\Previews\RequestPreview;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class FeatureRequestPreviewController extends Controller
{
    /**
     * Start a preview of the feature request's change.
     */
    public function store(FeatureRequest $featureRequest, RequestPreview $requestPreview): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $requestPreview->handle($featureRequest);

        return to_route('feature-requests.show', $featureRequest);
    }
}
