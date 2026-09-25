<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestFeature;
use App\Features\PatchSummary;
use App\Http\Requests\FeatureRequestStoreRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\RunEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FeatureRequestController extends Controller
{
    /**
     * Get the latest verification run for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestVerification(FeatureRequest $featureRequest): ?array
    {
        $verification = $featureRequest->verifications()->latest('id')->first();

        return $verification === null ? null : [
            'id' => $verification->id,
            'status' => $verification->status->value,
            'results' => $verification->results ?? [],
            'error' => $verification->error,
            'started_at' => $verification->started_at?->toIso8601String(),
            'finished_at' => $verification->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Get the latest construction run and its log for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestRun(FeatureRequest $featureRequest): ?array
    {
        $run = $featureRequest->latestRun;

        return $run === null ? null : [
            'id' => $run->id,
            'status' => $run->status->value,
            'driver' => $run->driver,
            'error' => $run->error,
            'workspace_revision' => $run->workspace_revision,
            'operations' => $run->operations()->count(),
            'budget' => [
                'operations' => (int) config('builder.construction.budgets.operations'),
                'minutes' => (int) config('builder.construction.budgets.minutes'),
            ],
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'events' => $run->events()->get()->map(fn (RunEvent $event) => [
                'sequence' => $event->sequence,
                'type' => $event->type,
                'data' => $event->data ?? [],
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
        ];
    }

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

        $parent = $featureRequest->parent;

        return Inertia::render('feature-requests/Show', [
            'project' => $featureRequest->project->only('id', 'name'),
            'featureRequest' => [
                'id' => $featureRequest->id,
                'prompt' => $featureRequest->prompt,
                'status' => $featureRequest->status->value,
                'summary' => $featureRequest->summary,
                'error' => $featureRequest->error,
                'target_step' => $parent === null || $featureRequest->target_step === null
                    ? null
                    : $parent->step($featureRequest->target_step),
                'steps' => $featureRequest->steps ?? [],
                'files' => PatchSummary::files($featureRequest->patch),
            ],
            'parent' => $parent?->only('id', 'prompt'),
            'verification' => $this->latestVerification($featureRequest),
            'run' => $this->latestRun($featureRequest),
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
