<?php

namespace App\Http\Controllers;

use App\Actions\Features\RequestFeature;
use App\Features\PatchSummary;
use App\Http\Requests\FeatureRequestStoreRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
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
            'plan' => $run->plan === null ? null : [
                'summary' => $run->plan['summary'],
                'acceptance_criteria' => $run->plan['acceptance_criteria'],
                'assumptions' => $run->plan['assumptions'],
                'understood_as' => $run->plan['understood_as'] ?? null,
                'current_behavior' => $run->plan['current_behavior'] ?? null,
                'preserve' => array_column($run->plan['preserve'] ?? [], 'statement'),
            ],
            'context' => $run->context === null ? null : [
                'mode' => $run->context['mode'],
                'targets' => $run->context['targets'],
                'included' => $run->context['included'],
                'tokens' => array_sum(array_column($run->context['included'], 'tokens')),
                'problems' => $run->context['problems'],
            ],
            'review' => $this->review($run),
            'repairs' => $run->repairs,
            'operations' => $run->operations()->count(),
            'budget' => [
                'operations' => (int) config('builder.construction.budgets.operations'),
                'minutes' => (int) config('builder.construction.budgets.minutes'),
                'repairs' => (int) config('builder.construction.budgets.repairs'),
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
     * Get the run's review for the owner: the behaviour changes and where the
     * change landed by area, with the areas' names.
     *
     * @return array<string, mixed>|null
     */
    protected function review(Run $run): ?array
    {
        if ($run->review === null) {
            return null;
        }

        $names = array_column($run->context['outline'] ?? [], 'name', 'key');
        $classification = $run->review['classification'];
        $areas = fn (array $files) => array_map(
            fn (string $key) => ['key' => $key, 'name' => $names[$key] ?? $key, 'files' => $files[$key]],
            array_keys($files),
        );

        return [
            'summary' => $run->review['summary'],
            'changes' => array_map(fn (array $change) => [
                ...$change,
                'area_name' => $change['area'] === null ? null : ($names[$change['area']] ?? $change['area']),
            ], $run->review['changes']),
            'areas' => [
                'requested' => $areas($classification['requested']),
                'may_also_affect' => $areas($classification['may_also_affect']),
                'unexpected' => $areas($classification['unexpected']),
            ],
            'preserved' => array_map(fn (array $item) => [
                ...$item,
                'area_name' => $item['area'] === null ? null : ($names[$item['area']] ?? $item['area']),
            ], $run->review['preserved'] ?? []),
            'unclaimed' => $classification['unclaimed'],
            'context_updates' => $classification['context_updates'],
        ];
    }

    /**
     * Get the latest preview for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestPreview(FeatureRequest $featureRequest): ?array
    {
        $preview = $featureRequest->previews()->latest('id')->first();

        return $preview === null ? null : [
            'id' => $preview->id,
            'status' => $preview->status->value,
            'error' => $preview->error,
            'url' => $preview->url(),
            'expires_at' => $preview->expires_at?->toIso8601String(),
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
            'preview' => $this->latestPreview($featureRequest),
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
