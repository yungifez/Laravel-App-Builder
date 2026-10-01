<?php

namespace App\Http\Controllers;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Previews\DescribeProjectPreview;
use App\Actions\Previews\ReadPreviewData;
use App\Actions\Previews\ReadPreviewEmails;
use App\Actions\Previews\ReadPreviewFiles;
use App\Actions\Previews\ReadPreviewPages;
use App\Actions\Previews\ReadPreviewPeople;
use App\Actions\Previews\ReadPreviewProblems;
use App\Actions\Previews\ReadPreviewRows;
use App\Actions\Previews\ReadPreviewSchedule;
use App\Actions\Projects\CreateProject;
use App\Actions\Projects\StartProjectFromTemplate;
use App\Actions\Projects\SummarizeChanges;
use App\Actions\Projects\SummarizeProjectTelemetry;
use App\Actions\Publishing\DescribeUnpublished;
use App\Actions\Runs\DescribeRunProgress;
use App\Actions\VisualEditing\InspectSelection;
use App\Actions\VisualEditing\ReadAppColors;
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
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
            // wants to go back to it. A design edit counts as work as much as
            // a request does.
            'projects' => $request->user()->projects()->withMax('featureRequests', 'created_at')->withMax('visualEdits', 'created_at')->get()
                ->sortByDesc(fn (Project $project) => $this->editedAt($project))
                ->values()
                ->map(fn (Project $project) => [
                    'id' => $project->uuid,
                    'name' => $project->name,
                    'edited_at' => $this->editedAt($project),
                    'published_at' => $this->publishedAt($project),
                    // How many changes wait for the owner, and whether the
                    // newest is being made or stopped.
                    ...$summarizeChanges->card($project),
                    // Kept changes the version online does not have yet.
                    'offline' => $this->offline($describeUnpublished->handle($project, $repository->exists($project) ? ($repository->head($project, Experiment::mainBranch()) ?: null) : null)),
                    // How many of the app's own tests guard it, as last run.
                    'tests' => TestObservation::countFor($project),
                    'picture' => $this->picture($project),
                ]),
            'canStartNew' => StartProjectFromTemplate::template() !== null,
            'designs' => array_map(fn (DesignDirection $design) => $design->preview(), DesignDirection::all()),
            'starters' => array_map(fn (Starter $starter) => $starter->toArray(), Starter::all()),
        ]);
    }

    /**
     * Get when the owner last worked on the app: asked for a change, edited
     * the design, or made it.
     */
    protected function editedAt(Project $project): ?string
    {
        $times = array_filter([
            $project->getAttribute('feature_requests_max_created_at'),
            $project->getAttribute('visual_edits_max_created_at'),
            $project->created_at,
        ]);

        return $times === [] ? null : collect($times)->map(fn ($time) => CarbonImmutable::parse($time))->max()?->toIso8601String();
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
     * the online version, and the design changes as one, however many
     * clicks they took.
     *
     * @param  array{added: list<array{id: string, asked: string}>, undone: list<array{id: string, asked: string}>, edits: int}|null  $unpublished
     */
    protected function offline(?array $unpublished): int
    {
        return $unpublished === null ? 0 : count($unpublished['added']) + count($unpublished['undone']) + min($unpublished['edits'], 1);
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
    public function show(Request $request, Project $project, ProjectRepository $repository, SummarizeProjectTelemetry $summarizeTelemetry, SummarizeChanges $summarizeChanges, DescribeProjectPreview $describePreview, InspectSelection $inspectSelection, DescribeFeatureRequest $describeFeatureRequest, DescribeUnpublished $describeUnpublished, ReadPreviewEmails $readPreviewEmails, ReadPreviewPeople $readPreviewPeople, ReadPreviewProblems $readPreviewProblems, ReadPreviewData $readPreviewData, ReadPreviewRows $readPreviewRows, ReadPreviewSchedule $readPreviewSchedule, ReadPreviewFiles $readPreviewFiles, ReadPreviewPages $readPreviewPages, ReadAppColors $readAppColors): Response
    {
        Gate::authorize('view', $project);

        // One of this app's changes, by its UUID. Anything else is not found:
        // the database refuses to compare a UUID with other text.
        $change = null;

        if ($request->filled('change')) {
            $id = $request->string('change')->toString();
            abort_unless(Str::isUuid($id), 404);
            $change = $project->featureRequests()->where('uuid', $id)->firstOrFail();
        }

        // Opening a change is reading what the owner was told about it.
        if ($change !== null) {
            $request->user()->unreadNotifications()->get()
                ->filter(fn (DatabaseNotification $notification) => ($notification->data['feature_request_id'] ?? null) === $change->id)
                ->each->markAsRead();
        }

        // The names of the app's colours, read once and only for a change
        // to how a part looks.
        $colors = fn (): array => once(fn () => $readAppColors->names($project));

        return Inertia::render('projects/Show', [
            'design' => $request->boolean('design'),
            'element' => Inertia::optional(fn () => $inspectSelection->handle($project, $request->query('target'), $request->boolean('instance'))),
            // The email the app on show has sent, read while the owner looks.
            // While they try a change, the page names its copy, and this and
            // the other tools beside the app read that copy.
            'emails' => Inertia::optional(fn () => $readPreviewEmails->handle($project)),
            // Who the owner can sign in to the app on show as, one tap each.
            'people' => Inertia::optional(fn () => $readPreviewPeople->handle($project)),
            // And the problems it ran into while the owner tried it.
            'problems' => Inertia::optional(fn () => $readPreviewProblems->handle($project)),
            // And what it has saved.
            'data' => Inertia::optional(fn () => $readPreviewData->handle($project)),
            // And the files it stored, such as uploads.
            'files' => Inertia::optional(fn () => $readPreviewFiles->handle($project)),
            // And the tasks it runs on its own.
            'schedule' => Inertia::optional(fn () => $readPreviewSchedule->handle($project)),
            // And its pages, to open one from the address bar.
            'pages' => Inertia::optional(fn () => $readPreviewPages->handle($project)),
            // The colours the app's stylesheets write, as its design system
            // names them, for the design panel.
            'colors' => Inertia::defer(fn () => $readAppColors->handle($project)),
            // And the rows of one table, when the owner opens it.
            'rows' => Inertia::optional(fn () => $request->filled('table') ? $readPreviewRows->handle($project, $request->string('table')->toString()) : null),
            'change' => fn () => $change === null ? null : $describeFeatureRequest->handle($change),
            'edits' => fn () => $project->visualEdits()->where('experiment_id', $project->experiment_id)->latest('id')->limit(10)->get()
                ->map(fn (VisualEdit $edit) => [
                    'id' => $edit->uuid,
                    'tag' => $edit->tag,
                    'device' => $edit->device,
                    'kind' => $edit->kind(),
                    'properties' => $edit->kind() === 'look' ? array_keys($edit->changes) : [],
                    'words' => $edit->changes[VisualEdit::TEXT]['after'] ?? null,
                    'words_before' => $edit->changes[VisualEdit::TEXT]['before'] ?? null,
                    'link' => $edit->changes[VisualEdit::LINK]['after'] ?? null,
                    'picture' => $edit->changes[VisualEdit::PICTURE]['after'] ?? null,
                    'picture_before' => $edit->changes[VisualEdit::PICTURE]['before'] ?? null,
                    // Which of the app's colours changed, for which look, and
                    // its value on each side, so undo and redo show at once.
                    'theme' => $edit->changes[VisualEdit::THEME] ?? null,
                    // What the element looks like after this edit, and the
                    // commit that made it, so the next automatic save can
                    // build on it without waiting for the rebuild.
                    'classes' => $edit->reverted_at === null ? $edit->classes_after : $edit->classes_before,
                    'revision' => $edit->reverted_at === null ? $edit->commit_sha : $edit->revert_sha,
                    // The app's version before this edit and right after it,
                    // so several undos in a row can each show at once.
                    'base' => $edit->base_revision,
                    'commit' => $edit->commit_sha,
                    // Where a removed part was written: the edit names the
                    // part left picked, its parent.
                    'removed' => $edit->changes[VisualEdit::REMOVE]['from'] ?? null,
                    // Where the part is and how it looks on each side of
                    // the edit, so undo and redo show in the running app
                    // straight away.
                    'target' => "{$edit->file}:{$edit->line}:{$edit->column}",
                    'sides' => $edit->kind() !== 'look' ? null : [
                        'before' => ['classes' => $edit->classes_before, 'values' => array_map(fn (array $value) => $value['value'], TailwindClasses::effective($edit->classes_before, $colors())[$edit->device] ?? [])],
                        'after' => ['classes' => $edit->classes_after, 'values' => array_map(fn (array $value) => $value['value'], TailwindClasses::effective($edit->classes_after, $colors())[$edit->device] ?? [])],
                    ],
                    'created_at' => $edit->created_at?->toIso8601String(),
                    'reverted_at' => $edit->reverted_at?->toIso8601String(),
                ]),
            'services' => fn () => $this->services($project),
            // The idea the owner is working in (null for the main app) and
            // the ideas still open, to move between.
            'ideas' => fn () => [
                'current' => $project->experiment === null ? null : ['id' => $project->experiment->uuid, ...$project->experiment->only('name', 'branch')],
                'open' => $project->experiments()->where('status', ExperimentStatus::Open)->latest('id')->get()->map(fn (Experiment $experiment) => ['id' => $experiment->uuid, ...$experiment->only('name', 'branch')]),
                'main' => Experiment::mainBranch(),
            ],
            'project' => fn () => [
                'id' => $project->uuid,
                'name' => $project->name,
                // Where an app brought in came from. An app started here
                // came from our own template, whose place is ours to keep.
                'source_path' => $project->started_here ? null : $project->source_path,
                'published_at' => $this->publishedAt($project),
                // How many of the app's own tests guard it, as last run.
                'tests' => TestObservation::countFor($project),
            ],
            'changes' => fn () => $summarizeChanges->handle($project),
            'preview' => fn () => $describePreview->handle($project),
            'history' => fn () => $repository->log($project, 20),
            'telemetry' => fn () => $summarizeTelemetry->handle($project),
            'publishing' => fn () => [
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
                        // Which check runs now, while it is checked first.
                        'doing' => $deployment->status === DeploymentStatus::Checking ? DescribeRunProgress::checks(count($deployment->checks ?? [])) : null,
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
