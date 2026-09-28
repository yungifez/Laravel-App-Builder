<?php

namespace App\Http\Controllers;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Previews\DescribeProjectPreview;
use App\Actions\Previews\ReadPreviewData;
use App\Actions\Previews\ReadPreviewEmails;
use App\Actions\Previews\ReadPreviewProblems;
use App\Actions\Previews\ReadPreviewRows;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\StartProjectFromTemplate;
use App\Actions\Projects\SummarizeChanges;
use App\Actions\Projects\SummarizeProjectTelemetry;
use App\Actions\Publishing\DescribeUnpublished;
use App\Actions\VisualEditing\InspectSelection;
use App\Enums\DeploymentStatus;
use App\Enums\ExperimentStatus;
use App\Enums\FeatureRequestStatus;
use App\Http\Requests\ProjectStoreRequest;
use App\Models\Deployment;
use App\Models\Experiment;
use App\Models\Project;
use App\Models\TestObservation;
use App\Models\Verification;
use App\Models\VisualEdit;
use App\Projects\DesignDirection;
use App\Projects\ProjectRepository;
use App\Projects\Starter;
use App\VisualEditing\TailwindClasses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * List the owner's apps with what they want to know at a glance: is it
     * live, when did it last change, and is anything waiting for them.
     */
    public function index(Request $request, SummarizeChanges $summarizeChanges, DescribeUnpublished $describeUnpublished, ProjectRepository $repository): Response
    {
        return Inertia::render('projects/Index', [
            // The app worked on last comes first, as the owner most likely
            // wants to go back to it.
            'projects' => $request->user()->projects()->withMax('featureRequests', 'created_at')->get()
                ->sortByDesc(fn (Project $project) => (string) ($project->getAttribute('feature_requests_max_created_at') ?? $project->created_at?->toDateTimeString()))
                ->values()
                ->map(fn (Project $project) => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'published_at' => $this->publishedAt($project),
                    'changed_at' => $project->featureRequests()->whereNotNull('commit_sha')->whereNull('reverted_at')
                        ->latest('accepted_at')->first()?->accepted_at?->toIso8601String(),
                    'waiting' => $summarizeChanges->waiting($project),
                    // Kept changes the version online does not have yet.
                    'offline' => $this->offline($describeUnpublished->handle($project, $repository->exists($project) ? ($repository->head($project, Experiment::mainBranch()) ?: null) : null)),
                    // How many of the app's own tests guard it, as last run.
                    'tests' => TestObservation::latestFor($project)?->testCount(),
                    'picture' => $this->picture($project),
                ]),
            'canStartNew' => StartProjectFromTemplate::template() !== null,
            'designs' => array_map(fn (DesignDirection $design) => $design->preview(), DesignDirection::all()),
            'starters' => array_map(fn (Starter $starter) => $starter->toArray(), Starter::all()),
        ]);
    }

    /**
     * Get a picture of the app: the screen check's picture from the latest
     * change that was kept or is waiting for the owner to try it. Never
     * one that was turned down or undone.
     */
    protected function picture(Project $project): ?string
    {
        $verifications = Verification::query()
            ->whereHas('featureRequest', fn ($query) => $query->whereBelongsTo($project)->whereNull('reverted_at')->where(fn ($query) => $query
                ->whereNotNull('commit_sha')
                ->orWhere(fn ($query) => $query->where('status', FeatureRequestStatus::Generated)->whereNull('dismissed_at'))))
            ->whereNotNull('screens')
            ->latest('id')
            ->limit(10)
            ->get();

        foreach ($verifications as $verification) {
            $shot = $verification->cover();

            if ($shot !== null) {
                return route('verifications.shots.show', [$verification, $shot]);
            }
        }

        return null;
    }

    /**
     * Count the changes waiting to go online: requests kept or undone since
     * the online version, and edits made by hand.
     *
     * @param  array{added: list<array{id: int, asked: string}>, undone: list<array{id: int, asked: string}>, edits: int}|null  $unpublished
     */
    protected function offline(?array $unpublished): int
    {
        return $unpublished === null ? 0 : count($unpublished['added']) + count($unpublished['undone']) + $unpublished['edits'];
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
     * Show the project's workspace: the conversation about its changes, the
     * app running beside it, and the design panel for changing how it looks.
     * The element the owner selected is loaded on request.
     */
    public function show(Request $request, Project $project, ProjectRepository $repository, SummarizeProjectTelemetry $summarizeTelemetry, SummarizeChanges $summarizeChanges, DescribeProjectPreview $describePreview, InspectSelection $inspectSelection, DescribeFeatureRequest $describeFeatureRequest, DescribeUnpublished $describeUnpublished, ReadPreviewEmails $readPreviewEmails, ReadPreviewProblems $readPreviewProblems, ReadPreviewData $readPreviewData, ReadPreviewRows $readPreviewRows): Response
    {
        Gate::authorize('view', $project);

        // Opening a change is reading what the owner was told about it.
        if ($request->filled('change')) {
            $request->user()->unreadNotifications()->get()
                ->filter(fn (DatabaseNotification $notification) => ($notification->data['feature_request_id'] ?? null) === $request->integer('change'))
                ->each->markAsRead();
        }

        return Inertia::render('projects/Show', [
            'design' => $request->boolean('design'),
            'element' => Inertia::optional(fn () => $inspectSelection->handle($project, $request->query('target'), $request->boolean('instance'))),
            // The email the app on show has sent, read while the owner looks.
            'emails' => Inertia::optional(fn () => $readPreviewEmails->handle($project)),
            // And the problems it ran into while the owner tried it.
            'problems' => Inertia::optional(fn () => $readPreviewProblems->handle($project)),
            // And what it has saved.
            'data' => Inertia::optional(fn () => $readPreviewData->handle($project)),
            // And the rows of one table, when the owner opens it.
            'rows' => Inertia::optional(fn () => $request->filled('table') ? $readPreviewRows->handle($project, $request->string('table')->toString()) : null),
            'change' => fn () => $request->filled('change')
                ? $describeFeatureRequest->handle($project->featureRequests()->findOrFail($request->integer('change')))
                : null,
            'edits' => $project->visualEdits()->where('experiment_id', $project->experiment_id)->latest('id')->limit(10)->get()
                ->map(fn (VisualEdit $edit) => [
                    'id' => $edit->id,
                    'tag' => $edit->tag,
                    'device' => $edit->device,
                    'kind' => $edit->kind(),
                    'properties' => $edit->kind() === 'look' ? array_keys($edit->changes) : [],
                    'words' => $edit->changes[VisualEdit::TEXT]['after'] ?? null,
                    'words_before' => $edit->changes[VisualEdit::TEXT]['before'] ?? null,
                    'link' => $edit->changes[VisualEdit::LINK]['after'] ?? null,
                    'picture' => $edit->changes[VisualEdit::PICTURE]['after'] ?? null,
                    'picture_before' => $edit->changes[VisualEdit::PICTURE]['before'] ?? null,
                    // What the element looks like after this edit, and the
                    // commit that made it, so the next automatic save can
                    // build on it without waiting for the rebuild.
                    'classes' => $edit->reverted_at === null ? $edit->classes_after : $edit->classes_before,
                    'revision' => $edit->reverted_at === null ? $edit->commit_sha : $edit->revert_sha,
                    // Where the part is and how it looks on each side of
                    // the edit, so undo and redo show in the running app
                    // straight away.
                    'target' => "{$edit->file}:{$edit->line}:{$edit->column}",
                    'sides' => $edit->kind() !== 'look' ? null : [
                        'before' => ['classes' => $edit->classes_before, 'values' => array_map(fn (array $value) => $value['value'], TailwindClasses::effective($edit->classes_before)[$edit->device] ?? [])],
                        'after' => ['classes' => $edit->classes_after, 'values' => array_map(fn (array $value) => $value['value'], TailwindClasses::effective($edit->classes_after)[$edit->device] ?? [])],
                    ],
                    'created_at' => $edit->created_at?->toIso8601String(),
                    'reverted_at' => $edit->reverted_at?->toIso8601String(),
                ]),
            'services' => fn () => $this->services($project),
            // The idea the owner is working in (null for the main app) and
            // the ideas still open, to move between.
            'ideas' => [
                'current' => $project->experiment?->only('id', 'name', 'branch'),
                'open' => $project->experiments()->where('status', ExperimentStatus::Open)->latest('id')->get(['id', 'name', 'branch']),
                'main' => Experiment::mainBranch(),
            ],
            'project' => [
                ...$project->only('id', 'name', 'source_path'),
                'published_at' => $this->publishedAt($project),
            ],
            'changes' => $summarizeChanges->handle($project),
            'preview' => $describePreview->handle($project),
            'history' => $repository->log($project, 20),
            'telemetry' => $summarizeTelemetry->handle($project),
            'publishing' => [
                'connected' => $project->publishable(),
                // We host it: the owner never chose a branch.
                'managed' => $project->publishingHost() !== 'git',
                'target' => $project->publishTarget(),
                'branch' => $project->deploy_branch,
                'address' => $project->live_url,
                // The main app's newest version: only it is ever published,
                // so the owner can see whether what they kept is online.
                'head' => $head = $repository->exists($project) ? ($repository->head($project, Experiment::mainBranch()) ?: null) : null,
                // What going online would change, in the owner's words.
                'unpublished' => $describeUnpublished->handle($project, $head, risks: true),
                'deployments' => $project->deployments()->latest('id')->limit(5)->get()
                    ->map(fn (Deployment $deployment) => [
                        'id' => $deployment->id,
                        'status' => $deployment->status->value,
                        'commit' => $deployment->commit_sha,
                        'checks' => $deployment->checks ?? [],
                        'error' => $deployment->error,
                        'health' => $deployment->health ?? [],
                        // A count only: the error text is for operators.
                        'problems' => $deployment->liveErrorCount(),
                        'pushed_at' => $deployment->pushed_at?->toIso8601String(),
                        'confirmed_at' => $deployment->confirmed_at?->toIso8601String(),
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

    /**
     * Get the outside services the app can use, and whether it does. The
     * keys themselves never go to the page.
     *
     * @return list<array{key: string, name: string, provider: string, about: string, keys_at: string, fields: list<array{name: string, label: string, hint: string|null, secret: bool}>, connected: bool}>
     */
    protected function services(Project $project): array
    {
        /** @var array<string, array{name: string, provider: string, about: string, keys_at: string, fields: array<string, array{label: string, hint?: string, secret?: bool}>}> $catalogue */
        $catalogue = config('builder.services', []);
        $services = [];

        foreach ($catalogue as $key => $service) {
            $fields = [];

            foreach ($service['fields'] as $name => $field) {
                $fields[] = ['name' => $name, 'label' => $field['label'], 'hint' => $field['hint'] ?? null, 'secret' => $field['secret'] ?? false];
            }

            $services[] = [
                'key' => $key,
                'name' => $service['name'],
                'provider' => $service['provider'],
                'about' => $service['about'],
                'keys_at' => $service['keys_at'],
                'fields' => $fields,
                'connected' => in_array($key, $project->connectedServices(), true),
            ];
        }

        return $services;
    }
}
