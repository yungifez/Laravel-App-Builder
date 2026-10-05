<?php

namespace App\Http\Controllers;

use App\Actions\Changes\AcceptChange;
use App\Actions\Changes\OpenKeptApp;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FeatureRequestAcceptanceController extends Controller
{
    /**
     * Accept the request's change into the project as a commit, or build
     * it again when the app changed after it was checked.
     */
    public function store(Request $request, FeatureRequest $featureRequest, AcceptChange $acceptChange, OpenKeptApp $openKeptApp): RedirectResponse
    {
        Gate::authorize('update', $featureRequest->project);

        $accepted = $acceptChange->handle($featureRequest, $request->user());

        if ($accepted->is($featureRequest)) {
            // The change is kept either way; only the app could not open.
            try {
                $openKeptApp->handle($accepted->project);
            } catch (ValidationException $exception) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);
            }

            return back();
        }

        // A change checked on an older app is built again instead. The
        // owner asked to keep it, so they are told why it is not kept yet.
        Inertia::flash('toast', ['type' => 'info', 'message' => __('Your app changed after I checked this, so I am making it again on your app as it is now. You can keep it when it is ready.')]);

        return to_route('projects.show', ['project' => $accepted->project, 'change' => $accepted->uuid]);
    }
}
