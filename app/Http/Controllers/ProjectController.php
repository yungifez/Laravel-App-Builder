<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SummarizeProjectTelemetry;
use App\Http\Requests\ProjectStoreRequest;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Projects\ProjectRepository;
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
     * Show a project, the feature requests made for it, its latest commits,
     * how its changes went, and where and when it was published.
     */
    public function show(Project $project, ProjectRepository $repository, SummarizeProjectTelemetry $summarizeTelemetry): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Show', [
            'project' => $project->only('id', 'name', 'source_path'),
            'featureRequests' => $project->featureRequests()->whereNull('parent_id')->latest()->get()
                ->map(fn (FeatureRequest $featureRequest) => [
                    'id' => $featureRequest->id,
                    'prompt' => $featureRequest->prompt,
                    'status' => $featureRequest->status->value,
                    'accepted' => $featureRequest->isAccepted(),
                    'created_at' => $featureRequest->created_at?->toIso8601String(),
                ]),
            'changes' => $project->featureRequests()->whereNotNull('accepted_at')->latest('accepted_at')->limit(20)->get()
                ->map(fn (FeatureRequest $featureRequest) => [
                    'id' => $featureRequest->id,
                    'summary' => $featureRequest->summary ?? $featureRequest->prompt,
                    'accepted_at' => $featureRequest->accepted_at?->toIso8601String(),
                    'reverted_at' => $featureRequest->reverted_at?->toIso8601String(),
                ]),
            'history' => $repository->log($project, 20),
            'telemetry' => $summarizeTelemetry->handle($project),
            'publishing' => [
                'connected' => $project->publishable(),
                'target' => $project->publishTarget(),
                'branch' => $project->deploy_branch,
                'deployments' => $project->deployments()->latest('id')->limit(5)->get()
                    ->map(fn (Deployment $deployment) => [
                        'id' => $deployment->id,
                        'status' => $deployment->status->value,
                        'commit' => $deployment->commit_sha,
                        'checks' => $deployment->checks ?? [],
                        'error' => $deployment->error,
                        'created_at' => $deployment->created_at?->toIso8601String(),
                        'finished_at' => $deployment->finished_at?->toIso8601String(),
                    ]),
            ],
        ]);
    }
}
