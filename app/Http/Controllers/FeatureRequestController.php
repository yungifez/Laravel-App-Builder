<?php

namespace App\Http\Controllers;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Features\RequestFeature;
use App\Actions\Features\StoreRequestImages;
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
    public function store(FeatureRequestStoreRequest $request, Project $project, RequestFeature $requestFeature, StoreRequestImages $storeRequestImages): RedirectResponse
    {
        $featureRequest = $requestFeature->handle(
            $project,
            $request->user(),
            $request->validated('prompt'),
            $request->validated('selection'),
            images: $storeRequestImages->handle($project, $request->file('images', [])),
        );

        return to_route('projects.show', ['project' => $project, 'change' => $featureRequest->uuid]);
    }

    /**
     * Show a feature request: the generated change, its steps and follow-ups.
     */
    public function show(FeatureRequest $featureRequest, DescribeFeatureRequest $describeFeatureRequest): Response
    {
        Gate::authorize('view', $featureRequest->project);

        return Inertia::render('feature-requests/Show', $describeFeatureRequest->handle($featureRequest));
    }
}
