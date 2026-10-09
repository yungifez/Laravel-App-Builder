<?php

namespace App\Http\Controllers;

use App\Actions\Features\HandChangeToOwner;
use App\Features\WorkerConnection;
use App\Models\FeatureRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FeatureRequestWorkerController extends Controller
{
    /**
     * Let the owner write the change with their own Claude Code or Codex.
     */
    public function store(Request $request, FeatureRequest $featureRequest, HandChangeToOwner $handChangeToOwner, WorkerConnection $connection): RedirectResponse
    {
        Gate::authorize('requestFeatures', $featureRequest->project);

        $handed = $handChangeToOwner->handle($featureRequest, $request->user());

        // Shown once: only the token's hash is kept.
        $connection->keep($request->user(), $handed['run'], $handed['token']);

        return to_route('projects.show', ['project' => $handed['change']->project, 'change' => $handed['change']->uuid]);
    }
}
