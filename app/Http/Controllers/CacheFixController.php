<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestCacheFix;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CacheFixController extends Controller
{
    /**
     * Ask for what kept the app from going online, before a change as well, to be fixed.
     */
    public function store(Request $request, FeatureRequest $featureRequest, RequestCacheFix $requestCacheFix): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $fix = $requestCacheFix->handle($featureRequest, $request->user());

        return to_route('projects.show', ['project' => $featureRequest->project, 'change' => $fix->uuid]);
    }
}
