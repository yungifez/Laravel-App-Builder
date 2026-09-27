<?php

namespace App\Http\Controllers;

use App\Actions\Context\CheckProjectNotes;
use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\UpdateProjectNotes;
use App\Context\Capability;
use App\Context\NotesDocument;
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
    public function show(Project $project, ProjectRepository $repository, ProjectNotes $projectNotes, ReadProjectContext $readProjectContext, CheckProjectNotes $checkProjectNotes): Response
    {
        Gate::authorize('view', $project);

        // An edit carries the version of the notes it was made on.
        $revision = $repository->exists($project) ? $projectNotes->version($project) : null;
        $context = $revision === null ? null : $readProjectContext->current($project);
        $notes = NotesDocument::parse($context->project ?? '');
        $names = array_map(fn (Capability $capability) => $capability->name, $context->capabilities ?? []);
        // Which of the app's tests run each area's own code, as last seen.
        $map = $context === null ? null : TestObservation::latestFor($project)?->map();

        return Inertia::render('projects/Understanding', [
            'project' => $project->only('id', 'name'),
            'revision' => $revision,
            'about' => [
                'introduction' => $notes->introduction,
                'sections' => array_values(array_filter($notes->sections, fn (array $section) => Str::lower($section['heading']) !== Str::lower(UpdateProjectNotes::GUIDANCE_SECTION))),
            ],
            'guidance' => $notes->section(UpdateProjectNotes::GUIDANCE_SECTION),
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
                'file' => $capability->file,
            ], $context->capabilities ?? [])),
            'problems' => $context->problems ?? [],
            'changes' => $project->featureRequests()->whereNotNull('accepted_at')->whereNull('reverted_at')->latest('accepted_at')->limit(10)->get()
                ->map(fn (FeatureRequest $featureRequest) => [
                    'id' => $featureRequest->id,
                    'summary' => $featureRequest->summary ?? $featureRequest->prompt,
                    'at' => $featureRequest->accepted_at?->toIso8601String(),
                ]),
            // All the changes kept, where the list above shows the latest.
            'kept' => $project->featureRequests()->whereNotNull('accepted_at')->whereNull('reverted_at')->count(),
            'looks' => $project->visualEdits()->count(),
            // Problems the checks or the second look caught in the changes
            // kept, each fixed before the owner saw the change.
            'caught' => RunEvent::query()->sentBack()->whereIn('run_id', Run::query()->select('id')->whereIn(
                'feature_request_id',
                $project->featureRequests()->select('id')->whereNotNull('accepted_at')->whereNull('reverted_at'),
            ))->count(),
            'draft' => $project->notes_draft_status === null ? null : [
                'status' => $project->notes_draft_status->value,
                'purpose' => $project->notes_draft['purpose'] ?? null,
                'areas' => array_map(fn (array $area) => [
                    'key' => $area['key'],
                    'name' => $area['name'],
                    'summary' => $area['summary'],
                    'behaviors' => array_column($area['behaviors'], 'name'),
                    'rules' => $area['rules'],
                ], $project->notes_draft['areas'] ?? []),
                'error' => $project->notes_draft_error,
            ],
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
