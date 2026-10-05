<?php

namespace App\Actions\Projects;

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;

class SummarizeProjectTelemetry
{
    public function __construct(protected MeasureChanges $measureChanges) {}

    /**
     * Summarise how changes to the project went: the four V1 measures (see
     * MeasureChanges), how many were undone, the repairs a kept change
     * needed, and how often a change left notes behind.
     *
     * Owner actions count each time the owner steered after asking
     * (architecture §31.4): adjusting part of a plan, stopping a run, trying
     * a stopped change again, or undoing a kept change. They are observed
     * actions, not proof that something went wrong, and not interventions:
     * classifying why the owner acted is an operator's judgement, kept
     * apart. Answering a question the builder asked is not counted; the
     * builder invited it.
     *
     * Setting the project up (drafting its notes) is reported apart, since
     * it belongs to no change.
     *
     * @return array{requests: int, kept: int, reverted: int, cost_usd: float, unpriced_calls: int, input_tokens: int, output_tokens: int, cost_per_kept_change_usd: float|null, runs_verified: int, first_attempt_passed: int, first_attempt_unverified: int, reviewed: int, with_unexpected_changes: int, edits_without_model: int, repairs_before_acceptance: float|null, with_notes_behind: int, setup_cost_usd: float, owner_actions: array{adjustments: int, stops: int, retries: int, undos: int}, owner_actions_per_kept_change: float|null}
     */
    public function handle(Project $project): array
    {
        $measures = $this->measureChanges->handle($project->featureRequests()->getQuery(), $project->visualEdits()->getQuery());
        $requests = $project->featureRequests()->get();
        $runIds = Run::query()->whereIn('feature_request_id', $requests->modelKeys())->pluck('id');

        $accepted = $requests->filter(fn (FeatureRequest $request) => $request->commit_sha !== null);
        $acceptedRuns = Run::query()->whereIn('feature_request_id', $accepted->modelKeys())->where('status', RunStatus::Completed)->get();
        $reviewed = Run::query()->whereIn('id', $runIds)->whereNotNull('review')->get();

        $reverted = $accepted->filter(fn (FeatureRequest $request) => $request->reverted_at !== null)->unique('commit_sha')->count();
        $ownerActions = [
            'adjustments' => $requests->filter(fn (FeatureRequest $request) => $request->parent_id !== null && $request->retry_of_id === null)->count(),
            'stops' => Run::query()->whereIn('id', $runIds)->where('status', RunStatus::Cancelled)->count(),
            'retries' => $requests->filter(fn (FeatureRequest $request) => $request->retry_of_id !== null)->count(),
            'undos' => $reverted,
        ];

        return [
            'requests' => $requests->count(),
            ...$measures,
            'reverted' => $reverted,
            'repairs_before_acceptance' => $acceptedRuns->isEmpty() ? null : round((float) $acceptedRuns->avg('repairs'), 2),
            // Changes that moved a part's code without rewriting its notes:
            // how fast the notes drift from the app.
            'with_notes_behind' => $reviewed->filter(fn (Run $run) => $run->review['classification']['notes_behind'] !== [])->count(),
            'setup_cost_usd' => round((float) collect($project->setup_model_calls ?? [])->sum(fn (array $call) => $call['cost_usd'] ?? 0), 4),
            'owner_actions' => $ownerActions,
            'owner_actions_per_kept_change' => $measures['kept'] > 0 ? round(array_sum($ownerActions) / $measures['kept'], 2) : null,
        ];
    }
}
