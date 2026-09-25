<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestFeature;
use App\Features\PatchSummary;
use App\Http\Requests\FeatureRequestStoreRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FeatureRequestController extends Controller
{
    /**
     * Request a feature for the project.
     */
    public function store(FeatureRequestStoreRequest $request, Project $project, RequestFeature $requestFeature): RedirectResponse
    {
        $featureRequest = $requestFeature->handle($project, $request->user(), $request->validated('prompt'));

        return to_route('feature-requests.show', $featureRequest);
    }

    /**
     * Show a feature request: the generated change, its steps and follow-ups.
     */
    public function show(FeatureRequest $featureRequest): Response
    {
        Gate::authorize('view', $featureRequest->project);

        $parent = $featureRequest->parent;

        return Inertia::render('feature-requests/Show', [
            'project' => $featureRequest->project->only('id', 'name'),
            'featureRequest' => [
                'id' => $featureRequest->id,
                'prompt' => $featureRequest->prompt,
                'status' => $featureRequest->status->value,
                'summary' => $featureRequest->summary,
                'error' => $featureRequest->error,
                'target_step' => $parent === null || $featureRequest->target_step === null
                    ? null
                    : $parent->step($featureRequest->target_step),
                'steps' => $featureRequest->steps ?? [],
                'files' => PatchSummary::files($featureRequest->patch),
            ],
            'parent' => $parent?->only('id', 'prompt'),
            'followUps' => $featureRequest->followUps()->latest()->get()
                ->map(fn (FeatureRequest $followUp) => [
                    'id' => $followUp->id,
                    'prompt' => $followUp->prompt,
                    'status' => $followUp->status->value,
                    'target_step' => $followUp->target_step,
                ]),
        ]);
    }
}
