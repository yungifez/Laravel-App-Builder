<?php

namespace App\Http\Controllers;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SummarizeChanges;
use App\Actions\Projects\SummarizeProjectTelemetry;
use App\Enums\DeploymentStatus;
use App\Http\Requests\ProjectStoreRequest;
use App\Models\Deployment;
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
     * List the owner's apps with what they want to know at a glance: is it
     * live, when did it last change, and is anything waiting for them.
     */
    public function index(Request $request, SummarizeChanges $summarizeChanges): Response
    {
        return Inertia::render('projects/Index', [
            'projects' => $request->user()->projects()->latest()->get()
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'published_at' => $this->publishedAt($project),
                    'changed_at' => $project->featureRequests()->whereNotNull('commit_sha')->whereNull('reverted_at')
                        ->latest('accepted_at')->first()?->accepted_at?->toIso8601String(),
                    'waiting' => $summarizeChanges->waiting($project),
                ]),
            'canStartNew' => filled(config('builder.projects.template')),
        ]);
    }

    /**
     * Register a new project. When I am drafting notes for it, the owner
     * goes to the page where they confirm them.
     */
    public function store(ProjectStoreRequest $request, CreateProject $createProject): RedirectResponse
    {
        $project = $createProject->handle(
            $request->user(),
            $request->validated('name'),
            $request->validated('source_path'),
        );

        return $project->notes_draft_status === null
            ? to_route('projects.show', $project)
            : to_route('projects.understanding.show', $project);
    }

    /**
     * Show a project, the feature requests made for it, its latest commits,
     * how its changes went, and where and when it was published.
     */
    public function show(Project $project, ProjectRepository $repository, SummarizeProjectTelemetry $summarizeTelemetry, SummarizeChanges $summarizeChanges): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('projects/Show', [
            'project' => [
                ...$project->only('id', 'name', 'source_path'),
                'published_at' => $this->publishedAt($project),
            ],
            'changes' => $summarizeChanges->handle($project),
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

    /**
     * When the app last went live, or null when it never has.
     */
    protected function publishedAt(Project $project): ?string
    {
        return $project->deployments()
            ->where('status', DeploymentStatus::Published)
            ->latest('id')
            ->first()
            ?->finished_at?->toIso8601String();
    }
}
