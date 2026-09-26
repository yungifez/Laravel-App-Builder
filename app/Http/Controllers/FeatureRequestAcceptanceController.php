<?php

namespace App\Http\Controllers;

use App\Actions\Changes\AcceptChange;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeatureRequestAcceptanceController extends Controller
{
    /**
     * Accept the request's change into the project as a commit.
     */
    public function store(Request $request, FeatureRequest $featureRequest, AcceptChange $acceptChange): RedirectResponse
    {
        Gate::authorize('update', $featureRequest->project);

        $acceptChange->handle($featureRequest, $request->user());

        return to_route('feature-requests.show', $featureRequest);
    }
}
