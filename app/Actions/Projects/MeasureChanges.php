<?php

namespace App\Actions\Projects;

use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Verification;
use App\Models\VisualEdit;
use Illuminate\Database\Eloquent\Builder;

class MeasureChanges
{
    /**
     * Answer the four questions V1 is judged by (architecture §27.9), for
     * one app or every app: what a kept change cost, how often the first
     * attempt passed, how often a change touched parts it was not about,
     * and how many edits were made without a model. The owner's view and
     * the operator's view both read them here, so a word means one thing.
     *
     * Costs cover every request, kept or not, since abandoned work is part
     * of what a kept change costs. The decision model's calls about a
     * request count too, though they are kept on the request, not a run.
     * Calls without a known price are counted, not guessed.
     *
     * A first attempt passes only when its checks passed with tests for the
     * change. Checks that passed with no such test (unverified) are counted
     * apart, never as passes.
     *
     * @param  Builder<FeatureRequest>  $requests
     * @param  Builder<VisualEdit>  $edits
     * @return array{kept: int, cost_usd: float, unpriced_calls: int, input_tokens: int, output_tokens: int, cost_per_kept_change_usd: float|null, runs_verified: int, first_attempt_passed: int, first_attempt_unverified: int, reviewed: int, with_unexpected_changes: int, edits_without_model: int}
     */
    public function handle(Builder $requests, Builder $edits): array
    {
        $requests = $requests->get();
        $runIds = Run::query()->whereIn('feature_request_id', $requests->modelKeys())->pluck('id');
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

        $kept = $requests->filter(fn (FeatureRequest $request) => $request->commit_sha !== null)->unique('commit_sha')->count();

        $finished = Verification::query()
            ->whereIn('run_id', $runIds)
            ->whereIn('id', Verification::query()->selectRaw('min(id)')->whereIn('run_id', $runIds)->groupBy('run_id'))
            ->get()
            ->filter(fn (Verification $verification) => $verification->status->finished());

        $reviewed = Run::query()->whereIn('id', $runIds)->whereNotNull('review')->get();

        return [
            'kept' => $kept,
            'cost_usd' => round($cost, 4),
            'unpriced_calls' => $unpriced,
            'input_tokens' => (int) $calls->sum(fn (array $call) => (int) ($call['input_tokens'] ?? 0)),
            'output_tokens' => (int) $calls->sum(fn (array $call) => (int) ($call['output_tokens'] ?? 0)),
            'cost_per_kept_change_usd' => $kept > 0 ? round($cost / $kept, 4) : null,
            'runs_verified' => $finished->count(),
            'first_attempt_passed' => $finished->filter(fn (Verification $verification) => $verification->status === VerificationStatus::Passed)->count(),
            'first_attempt_unverified' => $finished->filter(fn (Verification $verification) => $verification->status === VerificationStatus::Unverified)->count(),
            'reviewed' => $reviewed->count(),
            'with_unexpected_changes' => $reviewed->filter(fn (Run $run) => $run->review['classification']['unexpected'] !== [])->count(),
            'edits_without_model' => $edits->count(),
        ];
    }
}
