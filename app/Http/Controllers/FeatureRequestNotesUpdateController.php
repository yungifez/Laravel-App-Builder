<?php

namespace App\Http\Controllers;

use App\Actions\Context\RequestNotesUpdate;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class FeatureRequestNotesUpdateController extends Controller
{
    /**
     * Bring the notes a kept change left behind up to date.
     */
    public function store(FeatureRequest $featureRequest, RequestNotesUpdate $requestNotesUpdate): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $requestNotesUpdate->handle($featureRequest);

        return back();
    }
}
