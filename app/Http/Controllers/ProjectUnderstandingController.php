<?php

namespace App\Http\Controllers;

use App\Actions\Context\CheckProjectNotes;
use App\Actions\Context\EstimateExploration;
use App\Actions\Context\ListGuidanceHistory;
use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\RecordDecision;
use App\Actions\Context\UpdateProjectNotes;
use App\Actions\Features\DescribeAskedFor;
use App\Actions\Features\ListDecisions;
use App\Actions\Features\ListLaterIdeas;
use App\Actions\Features\TallyKeptProof;
use App\Context\Capability;
use App\Context\NotesDocument;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\EffectStrength;
use App\Http\Requests\ProjectNotesUpdateRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\TestObservation;
use App\Projects\ProjectRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProjectUnderstandingController extends Controller
{
    /**
     * Show what the notes say about the app, in the owner's words: what it is
     * for, how things work, what must always be true, what is connected, and
     * what changed. The quick check runs on request.
     */
    public function show(Project $project, ProjectRepository $repository, ProjectNotes $projectNotes, ReadProjectContext $readProjectContext, CheckProjectNotes $checkProjectNotes, DescribeAskedFor $describeAskedFor, TallyKeptProof $tallyKeptProof, ListDecisions $listDecisions, EstimateExploration $estimateExploration): Response
    {
        Gate::authorize('view', $project);

        // An edit carries the version of the notes it was made on.
        $revision = $repository->exists($project) ? $projectNotes->version($project) : null;
        $context = $revision === null ? null : $readProjectContext->current($project);
        $notes = NotesDocument::parse($context->project ?? '');
        $names = array_map(fn (Capability $capability) => $capability->name, $context->capabilities ?? []);
        // The app's own tests, as last run, and which run each area's code.
        $observation = TestObservation::latestFor($project);
        $map = $context === null ? null : $observation?->map();
        $askedFor = $context === null ? [] : $describeAskedFor->handle($project, $repository->head($project) ?: null);
        $kept = $project->featureRequests()->whereNotNull('accepted_at')->whereNull('reverted_at');
        // The owner's last look, before this one moves it on.
        $since = $project->understanding_seen_at;
        $project->forceFill(['understanding_seen_at' => now()])->saveQuietly();

        return Inertia::render('projects/Understanding', [
            'project' => ['id' => $project->uuid, 'name' => $project->name],
            // The count the builder's header links here with.
            'tests' => TestObservation::countFor($project),
            // What those tests check, by file, for an app whose parts are
            // not described yet: the header's "see what they check" lands
            // on them rather than on nothing.
            'checks' => fn () => TestObservation::checksFor($project),
            'revision' => $revision,
            'about' => [
                'introduction' => $notes->introduction,
                // Guidance, the goal and decisions each show in a place of their own.
                'sections' => array_values(array_filter($notes->sections, fn (array $section) => ! in_array(Str::lower($section['heading']), [Str::lower(UpdateProjectNotes::GUIDANCE_SECTION), Str::lower(UpdateProjectNotes::GOAL_SECTION), Str::lower(RecordDecision::SECTION)], true))),
            ],
            'goal' => $notes->section(UpdateProjectNotes::GOAL_SECTION),
            // Whether changes keep the app's old data and links working, and
            // whether that is the owner's choice or follows from its use.
            'compatibility' => [
                'keep' => $project->keepsOldWorking(),
                'chosen' => $project->keep_old_working !== null,
                'in_use' => $project->mayBeInUse(),
            ],
            'guidance' => $notes->section(UpdateProjectNotes::GUIDANCE_SECTION),
            // The outside services the app is connected to, by name only.
            'services' => array_map(fn (string $service) => [
                'name' => config("builder.services.{$service}.name"),
                'provider' => config("builder.services.{$service}.provider"),
            ], $project->connectedServices()),
            // What the owner might add later, as offered with kept changes.
            'later' => fn () => app(ListLaterIdeas::class)->handle($project),
            // Its earlier wordings, so a change to it can be traced and undone.
            'guidanceHistory' => fn () => app(ListGuidanceHistory::class)->handle($project, Auth::id()),
            'areas' => array_values(array_map(fn (Capability $capability) => [
                'key' => $capability->key,
                'name' => $capability->name,
                'summary' => $capability->summary,
                'behaviors' => array_column($capability->behaviors, 'name'),
                'rules' => $capability->rules(),
                'connections' => $this->connections($capability, $names),
                'tested' => $capability->testFiles !== [],
                'checked_by' => $map === null ? null : count($map->testsForArea($capability)),
                // What those tests check, in their authors' words.
                'checks' => $map === null ? [] : array_values(array_unique(array_map($map->sentence(...), $map->testsForArea($capability)))),
                // What the owner asked for here, each proved by a test when kept.
                'asked_for' => $askedFor[$capability->key] ?? [],
                // Whether the owner asked to be extra careful here.
                'careful' => $project->isCareful($capability->key),
                'file' => $capability->file,
            ], $context->capabilities ?? [])),
            'problems' => $context->problems ?? [],
            'changes' => (clone $kept)->latest('accepted_at')->limit(10)->get()
                ->map(fn (FeatureRequest $featureRequest) => [
                    'id' => $featureRequest->uuid,
                    'summary' => $featureRequest->summary ?? $featureRequest->prompt,
                    'at' => $featureRequest->accepted_at?->toIso8601String(),
                ]),
            // All the changes kept, where the list above shows the latest.
            'kept' => (clone $kept)->count(),
            // What changed while the owner was away: none on a first look.
            'since' => $since?->toIso8601String(),
            'fresh' => $since === null ? 0 : (clone $kept)->where('accepted_at', '>', $since)->count(),
            'looks' => $project->visualEdits()->count(),
            // Problems the checks or the second look caught in the changes
            // kept, each fixed before the owner saw the change.
            'caught' => RunEvent::query()->sentBack()->whereIn('run_id', Run::query()->select('id')->whereIn(
                'feature_request_id',
                $project->featureRequests()->select('id')->whereNotNull('accepted_at')->whereNull('reverted_at'),
            ))->count(),
            // Shortcuts in the code the builder fixed on its own, in tidy-ups
            // still in the app.
            'tidied' => (clone $kept)->whereNotNull('tidy')->get()
                ->sum(fn (FeatureRequest $featureRequest) => count($featureRequest->tidy['shortcuts'] ?? [])),
            // The tests the kept changes added, and their screens found to fit.
            'proven' => $tallyKeptProof->handle($project),
            'decisions' => array_slice($decisions = $listDecisions->handle($project, $notes->section(RecordDecision::SECTION), limit: null), 0, 12),
            // All of them, where the list shows the newest.
            'decided' => count($decisions),
            'draft' => $project->notes_draft_status === null ? null : [
                'status' => $project->notes_draft_status->value,
                'purpose' => $project->notes_draft['purpose'] ?? null,
                'areas' => array_map(fn (array $area) => [
                    'key' => $area['key'],
                    'name' => $area['name'],
                    'summary' => $area['summary'],
                    'behaviors' => array_column($area['behaviors'], 'name'),
                    'rules' => $area['rules'],
                    // What backs the area without a model, for the owner's check.
                    'tests' => $area['tests'] ?? null,
                    'pages' => $area['pages'] ?? [],
                    // A draft from before exploring has no evidence to show.
                    'explored' => array_key_exists('tests', $area),
                ], $project->notes_draft['areas'] ?? []),
                'error' => $project->notes_draft_error,
            ],
            // An app without notes can be explored, at a cost the owner reads first.
            'exploration' => $revision === null || $project->notes_draft_status !== null || isset($projectNotes->files($project)[ProjectContext::PROJECT_FILE])
                ? null
                : fn () => $estimateExploration->handle($project),
            'check' => Inertia::optional(fn () => $revision === null ? [] : $checkProjectNotes->handle($project)),
        ]);
    }

    /**
     * Save the owner's edit to one part of the notes.
     */
    public function update(ProjectNotesUpdateRequest $request, Project $project, UpdateProjectNotes $updateProjectNotes): RedirectResponse
    {
        $updateProjectNotes->handle(
            $project,
            $request->validated('part'),
            (string) $request->validated('body'),
            $request->validated('revision'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. I will use this from now on.')]);

        return back();
    }

    /**
     * Get an area's connections for the owner, one per connected area. The
     * notes, the tests and past changes can each link the same two areas;
     * the owner sees the link once, at its strongest, with every reason.
     * Observed reasons name code files, so the owner hears where it was seen.
     *
     * @param  array<string, string>  $names
     * @return list<array{to: string, name: string, reason: string, strength: string}>
     */
    protected function connections(Capability $capability, array $names): array
    {
        $order = [EffectStrength::Strong, EffectStrength::Possible, EffectStrength::Historical];
        $connections = [];

        foreach ($capability->effects as $effect) {
            $reason = $effect->source === 'tests' ? __('Seen when your app\'s tests ran.') : $effect->reason;
            $known = $connections[$effect->to] ?? null;

            $connections[$effect->to] = [
                'to' => $effect->to,
                'name' => $names[$effect->to] ?? Str::headline($effect->to),
                'reasons' => array_values(array_unique([...$known['reasons'] ?? [], $reason])),
                'strength' => $known === null || array_search($effect->strength, $order, true) < array_search($known['strength'], $order, true) ? $effect->strength : $known['strength'],
            ];
        }

        return array_values(array_map(fn (array $connection) => [
            'to' => $connection['to'],
            'name' => $connection['name'],
            'reason' => implode(' ', $connection['reasons']),
            'strength' => $connection['strength']->value,
        ], $connections));
    }
}
