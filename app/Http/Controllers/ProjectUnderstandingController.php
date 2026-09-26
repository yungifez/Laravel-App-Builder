<?php

namespace App\Http\Controllers;

use App\Actions\Context\CheckProjectNotes;
use App\Actions\Context\ReadProjectContext;
use App\Actions\Context\UpdateProjectNotes;
use App\Context\Capability;
use App\Context\Effect;
use App\Context\NotesDocument;
use App\Http\Requests\ProjectNotesUpdateRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
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
    public function show(Project $project, ProjectRepository $repository, ReadProjectContext $readProjectContext, CheckProjectNotes $checkProjectNotes): Response
    {
        Gate::authorize('view', $project);

        $revision = $repository->exists($project) ? $repository->head($project) : null;
        $context = $revision === null ? null : $readProjectContext->atRevision($project, $revision);
        $notes = NotesDocument::parse($context->project ?? '');
        $names = array_map(fn (Capability $capability) => $capability->name, $context->capabilities ?? []);

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
                'connections' => array_map(fn (Effect $effect) => [
                    'to' => $effect->to,
                    'name' => $names[$effect->to] ?? Str::headline($effect->to),
                    'reason' => $effect->reason,
                    'strength' => $effect->strength->value,
                ], $capability->effects),
                'tested' => $capability->testFiles !== [],
                'file' => $capability->file,
            ], $context->capabilities ?? [])),
            'problems' => $context->problems ?? [],
            'changes' => $project->featureRequests()->whereNotNull('accepted_at')->whereNull('reverted_at')->latest('accepted_at')->limit(10)->get()
                ->map(fn (FeatureRequest $featureRequest) => [
                    'id' => $featureRequest->id,
                    'summary' => $featureRequest->summary ?? $featureRequest->prompt,
                    'at' => $featureRequest->accepted_at?->toIso8601String(),
                ]),
            'looks' => $project->visualEdits()->count(),
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
            'check' => Inertia::optional(fn () => $revision === null ? [] : $checkProjectNotes->handle($project, $revision)),
        ]);
    }

    /**
     * Save the owner's edit to one part of the notes.
     */
    public function update(ProjectNotesUpdateRequest $request, Project $project, UpdateProjectNotes $updateProjectNotes): RedirectResponse
    {
        $updateProjectNotes->handle(
            $project,
            $request->user(),
            $request->validated('part'),
            (string) $request->validated('body'),
            $request->validated('revision'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. I will use this from now on.')]);

        return back();
    }
}
