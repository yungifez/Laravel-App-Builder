<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Http\Requests\ProjectStoreRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * List the user's projects.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('projects/Index', [
            'projects' => $request->user()->projects()->latest()->get()
                ->map(fn (Project $project) => $project->only('id', 'name', 'source_path')),
        ]);
    }

    /**
     * Register a new project.
     */
    public function store(ProjectStoreRequest $request, CreateProject $createProject): RedirectResponse
    {
        $project = $createProject->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('source_path'),
        );

        return to_route('projects.show', $project);
    }

    /**
     * Show a project and the feature requests made for it.
     */
    public function show(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Show', [
            'project' => $project->only('id', 'name', 'source_path'),
            'featureRequests' => $project->featureRequests()->whereNull('parent_id')->latest()->get()
                ->map(fn (FeatureRequest $featureRequest) => [
                    'id' => $featureRequest->id,
                    'prompt' => $featureRequest->prompt,
                    'status' => $featureRequest->status->value,
                    'created_at' => $featureRequest->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
