<?php

namespace App\Http\Controllers;

use App\Actions\Changes\RevertChange;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeatureRequestReversionController extends Controller
{
    /**
     * Undo the request's accepted change with a new commit.
     */
    public function store(Request $request, FeatureRequest $featureRequest, RevertChange $revertChange): RedirectResponse
    {
        Gate::authorize('update', $featureRequest->project);

        $revertChange->handle($featureRequest, $request->user());

        return to_route('feature-requests.show', $featureRequest);
    }
}
