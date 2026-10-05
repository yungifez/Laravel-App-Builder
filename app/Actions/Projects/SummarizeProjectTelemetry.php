<?php

namespace App\Actions\Projects;

use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Verification;

class SummarizeProjectTelemetry
{
    /**
     * Summarise how changes to the project went: what they cost per accepted
     * change, how often the first attempt passed verification, how often a
     * change touched areas it was not about, and how many were undone. Visual
     * edits are counted apart: they change the app without a model call.
     *
     * Owner actions count each time the owner steered after asking
     * (architecture §31.4): adjusting part of a plan, stopping a run, trying
     * a stopped change again, or undoing a kept change. They are observed
     * actions, not proof that something went wrong, and not interventions:
     * classifying why the owner acted is an operator's judgement, kept
     * apart. Answering a question the builder asked is not counted; the
     * builder invited it.
     *
     * A first attempt passes only when its checks passed with tests for the
     * change. Checks that passed with no such test (unverified) are counted
     * apart, never as passes.
     *
     * Costs cover every request, accepted or not, since abandoned work is
     * part of what an accepted change costs. Calls without a known price are
     * counted, not guessed. Setting the project up (drafting its notes) is
     * reported apart, since it belongs to no change.
     *
     * @return array{requests: int, accepted: int, reverted: int, cost_usd: float, unpriced_calls: int, cost_per_accepted_change_usd: float|null, runs_verified: int, first_attempt_passed: int, first_attempt_unverified: int, repairs_before_acceptance: float|null, reviewed: int, with_unexpected_changes: int, with_notes_behind: int, input_tokens: int, output_tokens: int, visual_edits: int, setup_cost_usd: float, owner_actions: array{adjustments: int, stops: int, retries: int, undos: int}, owner_actions_per_accepted_change: float|null}
     */
    public function handle(Project $project): array
    {
        $requests = $project->featureRequests()->get();
        $runIds = Run::query()->whereIn('feature_request_id', $requests->modelKeys())->pluck('id');
        // The decision model's calls about a request are part of what the
        // change cost too, though they are kept on the request, not a run.
        $calls = RunEvent::query()->whereIn('run_id', $runIds)->where('type', 'model_call')->pluck('data')
            ->merge($requests->flatMap(fn (FeatureRequest $request) => $request->decision_model_calls ?? []));

        $cost = 0.0;
        $unpriced = 0;

        foreach ($calls as $call) {
            $price = $call['cost_usd'] ?? null;

            if (is_numeric($price)) {
                $cost += (float) $price;
            } else {
                $unpriced++;
            }
        }

        $accepted = $requests->filter(fn (FeatureRequest $request) => $request->commit_sha !== null);
        $acceptedCount = $accepted->unique('commit_sha')->count();

        $firstVerifications = Verification::query()
            ->whereIn('run_id', $runIds)
            ->whereIn('id', Verification::query()->selectRaw('min(id)')->whereIn('run_id', $runIds)->groupBy('run_id'))
            ->get();

        $finished = $firstVerifications->filter(fn (Verification $verification) => $verification->status->finished());
        $reviewed = Run::query()->whereIn('id', $runIds)->whereNotNull('review')->get();
        $acceptedRuns = Run::query()->whereIn('feature_request_id', $accepted->modelKeys())->where('status', RunStatus::Completed)->get();

        $reverted = $accepted->filter(fn (FeatureRequest $request) => $request->reverted_at !== null)->unique('commit_sha')->count();
        $ownerActions = [
            'adjustments' => $requests->filter(fn (FeatureRequest $request) => $request->parent_id !== null && $request->retry_of_id === null)->count(),
            'stops' => Run::query()->whereIn('id', $runIds)->where('status', RunStatus::Cancelled)->count(),
            'retries' => $requests->filter(fn (FeatureRequest $request) => $request->retry_of_id !== null)->count(),
            'undos' => $reverted,
        ];

        return [
            'requests' => $requests->count(),
            'accepted' => $acceptedCount,
            'reverted' => $reverted,
            'cost_usd' => round($cost, 4),
            'unpriced_calls' => $unpriced,
            'cost_per_accepted_change_usd' => $acceptedCount > 0 ? round($cost / $acceptedCount, 4) : null,
            'runs_verified' => $finished->count(),
            'first_attempt_passed' => $finished->filter(fn (Verification $verification) => $verification->status === VerificationStatus::Passed)->count(),
            'first_attempt_unverified' => $finished->filter(fn (Verification $verification) => $verification->status === VerificationStatus::Unverified)->count(),
            'repairs_before_acceptance' => $acceptedRuns->isEmpty() ? null : round((float) $acceptedRuns->avg('repairs'), 2),
            'reviewed' => $reviewed->count(),
            'with_unexpected_changes' => $reviewed->filter(fn (Run $run) => $run->review['classification']['unexpected'] !== [])->count(),
            // Changes that moved a part's code without rewriting its notes:
            // how fast the notes drift from the app.
            'with_notes_behind' => $reviewed->filter(fn (Run $run) => $run->review['classification']['notes_behind'] !== [])->count(),
            'input_tokens' => (int) $calls->sum(fn (array $call) => (int) ($call['input_tokens'] ?? 0)),
            'output_tokens' => (int) $calls->sum(fn (array $call) => (int) ($call['output_tokens'] ?? 0)),
            'visual_edits' => $project->visualEdits()->count(),
            'setup_cost_usd' => round((float) collect($project->setup_model_calls ?? [])->sum(fn (array $call) => $call['cost_usd'] ?? 0), 4),
            'owner_actions' => $ownerActions,
            'owner_actions_per_accepted_change' => $acceptedCount > 0 ? round(array_sum($ownerActions) / $acceptedCount, 2) : null,
        ];
    }
}
