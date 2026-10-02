<?php

namespace App\Http\Controllers;

use App\Actions\Features\AcceptFindings;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeatureRequestAcceptedFindingController extends Controller
{
    /**
     * Say the change does what one boundary rule found on purpose.
     */
    public function store(Request $request, FeatureRequest $featureRequest, string $kind, AcceptFindings $acceptFindings): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $acceptFindings->handle($featureRequest, $kind, $request->user());

        return back();
    }

    /**
     * Take that back, so the rule's findings count again.
     */
    public function destroy(FeatureRequest $featureRequest, string $kind, AcceptFindings $acceptFindings): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $acceptFindings->restore($featureRequest, $kind);

        return back();
    }
}
