<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestFeature;
use App\Http\Requests\FeatureRequestStoreRequest;
use App\Http\Resources\FeatureRequestResource;
use App\Http\Resources\PreviewResource;
use App\Http\Resources\RunResource;
use App\Http\Resources\VerificationResource;
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

        return Inertia::render('feature-requests/Show', [
            'project' => $featureRequest->project->only('id', 'name'),
            'featureRequest' => $featureRequest->toResource(FeatureRequestResource::class),
            'parent' => $featureRequest->parent?->only('id', 'prompt'),
            'verification' => $featureRequest->verifications()->latest('id')->first()?->toResource(VerificationResource::class),
            'run' => $featureRequest->latestRun?->toResource(RunResource::class),
            'preview' => $featureRequest->previews()->latest('id')->first()?->toResource(PreviewResource::class),
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
