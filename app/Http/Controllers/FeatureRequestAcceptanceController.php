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
     * Accept the request's change into the project as a commit, or build
     * it again when the app changed after it was checked.
     */
    public function store(Request $request, FeatureRequest $featureRequest, AcceptChange $acceptChange): RedirectResponse
    {
        Gate::authorize('update', $featureRequest->project);

        $accepted = $acceptChange->handle($featureRequest, $request->user());

        // A change checked on an older app is built again instead.
        return $accepted->is($featureRequest)
            ? back()
            : to_route('projects.show', ['project' => $accepted->project_id, 'change' => $accepted->id]);
    }
}
