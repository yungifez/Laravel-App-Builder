<?php

namespace App\Http\Controllers;

use App\Actions\Features\HandChangeToOwner;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class FeatureRequestWorkerController extends Controller
{
    /**
     * Let the owner write the change with their own Claude Code or Codex.
     */
    public function store(Request $request, FeatureRequest $featureRequest, HandChangeToOwner $handChangeToOwner): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $handed = $handChangeToOwner->handle($featureRequest, $request->user());

        // Shown once: only the token's hash is kept.
        Inertia::flash('worker', ['run' => $handed['run']->uuid, 'token' => $handed['token']]);

        return to_route('projects.show', ['project' => $handed['change']->project, 'change' => $handed['change']->uuid]);
    }
}
