<?php

namespace App\Actions\Projects;

use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Features\TestReport;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Verification;
use Illuminate\Support\Collection;

/**
 * Measure how a project's changes went as the app grew (the Evolution
 * Benchmark, direction 21 §15). Making one app is easy; the question is
 * whether change 35 still goes as well as change 5. So the kept changes are
 * cut into windows that end at fixed checkpoints (1, 5, 10, 20, 35, 50 by
 * default), and each window says what its changes cost and how often they
 * went wrong.
 *
 * Every request belongs to the window of the first change kept after it
 * was asked, so abandoned and repaired work counts against the change that
 * was finally kept. Requests after the last kept change are left out: they
 * are not done yet. The definitions follow SummarizeProjectTelemetry.
 *
 * - broken tests: tests a change made fail that passed on its starting
 *   commit (a regression the checks caught before it was kept);
 * - earlier rules broken: rules of earlier kept changes a change broke (a
 *   rule the app forgot);
 * - owner steps: adjustments, retries and stops (the owner had to correct).
 *
 * @phpstan-type Window array{from: int, to: int, kept: int, requests: int, cost_usd: float, unpriced_calls: int, tokens: int, first_attempt_passed: int, first_attempts: int, repairs: int, owner_steps: int, broken_tests: int, earlier_rules_broken: int, undone: int, hours_to_keep: float|null}
 */
class MeasureEvolution
{
    /**
     * @param  list<int>  $checkpoints  The kept-change counts each window ends at, ascending
     * @return list<Window>
     */
    public function handle(Project $project, array $checkpoints): array
    {
        $requests = $project->featureRequests()->oldest('id')->get();
        $kept = $requests
            ->filter(fn (FeatureRequest $request) => $request->commit_sha !== null && $request->accepted_at !== null)
            ->unique('commit_sha')
            ->sortBy('accepted_at')
            ->values();

        if ($kept->isEmpty()) {
            return [];
        }

        // The position of the kept change each request led to.
        $position = fn (FeatureRequest $request) => $kept->search(fn (FeatureRequest $change) => $change->accepted_at >= $request->created_at);
        $windows = [];
        $from = 1;

        foreach ($checkpoints as $to) {
            if ($from > $kept->count()) {
                break;
            }

            $to = min($to, $kept->count());
            $changes = $kept->slice($from - 1, $to - $from + 1);
            $members = $requests->filter(function (FeatureRequest $request) use ($position, $from, $to) {
                $at = $position($request);

                return $at !== false && $at + 1 >= $from && $at + 1 <= $to;
            });

            $windows[] = ['from' => $from, 'to' => $to, ...$this->measure($changes, $members)];
            $from = $to + 1;
        }

        return $windows;
    }

    /**
     * Measure one window: its kept changes, and every request that led to
     * them.
     *
     * @param  Collection<int, FeatureRequest>  $changes
     * @param  Collection<int, FeatureRequest>  $requests
     * @return array{kept: int, requests: int, cost_usd: float, unpriced_calls: int, tokens: int, first_attempt_passed: int, first_attempts: int, repairs: int, owner_steps: int, broken_tests: int, earlier_rules_broken: int, undone: int, hours_to_keep: float|null}
     */
    protected function measure(Collection $changes, Collection $requests): array
    {
        $runs = Run::query()->whereIn('feature_request_id', $requests->pluck('id'))->get();
        $calls = RunEvent::query()->whereIn('run_id', $runs->modelKeys())->where('type', 'model_call')->get();
        $verifications = Verification::query()->whereIn('run_id', $runs->modelKeys())->oldest('id')->get();
        $first = $verifications->unique('run_id')->filter(fn (Verification $verification) => $verification->status->finished());

        $hours = $changes
            ->map(function (FeatureRequest $change) {
                $asked = $change->lineage()[0]->created_at;

                return $asked === null || $change->accepted_at === null ? null : $asked->diffInMinutes($change->accepted_at) / 60;
            })
            ->filter(fn (?float $hours) => $hours !== null)
            ->sort()
            ->values();

        return [
            'kept' => $changes->count(),
            'requests' => $requests->count(),
            'cost_usd' => round((float) $calls->sum(fn (RunEvent $call) => is_numeric($call->data['cost_usd'] ?? null) ? (float) $call->data['cost_usd'] : 0.0), 4),
            'unpriced_calls' => $calls->filter(fn (RunEvent $call) => ! is_numeric($call->data['cost_usd'] ?? null))->count(),
            'tokens' => (int) $calls->sum(fn (RunEvent $call) => (int) ($call->data['input_tokens'] ?? 0) + (int) ($call->data['output_tokens'] ?? 0)),
            'first_attempt_passed' => $first->filter(fn (Verification $verification) => $verification->status === VerificationStatus::Passed)->count(),
            'first_attempts' => $first->count(),
            'repairs' => (int) $runs->sum('repairs'),
            'owner_steps' => $requests->filter(fn (FeatureRequest $request) => $request->parent_id !== null || $request->retry_of_id !== null)->count()
                + $runs->filter(fn (Run $run) => $run->status === RunStatus::Cancelled)->count(),
            'broken_tests' => (int) $verifications->sum(fn (Verification $verification) => collect($verification->results ?? [])
                ->filter(fn (array $result) => ($result['at_start'] ?? null) === VerifyFeatureRequest::OUTCOME_PASSED)
                ->sum(fn (array $result) => collect($result['tests'] ?? [])->where('outcome', TestReport::FAILED)->count())),
            'earlier_rules_broken' => (int) $verifications->sum(fn (Verification $verification) => count($verification->evidence['earlier_rules'] ?? [])),
            'undone' => $changes->filter(fn (FeatureRequest $change) => $change->reverted_at !== null)->count(),
            'hours_to_keep' => $hours->isEmpty() ? null : round((float) $hours->median(), 2),
        ];
    }
}
