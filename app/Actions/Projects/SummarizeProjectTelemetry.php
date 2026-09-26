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
     * Costs cover every request, accepted or not, since abandoned work is
     * part of what an accepted change costs. Calls without a known price are
     * counted, not guessed. Setting the project up (drafting its notes) is
     * reported apart, since it belongs to no change.
     *
     * @return array{requests: int, accepted: int, reverted: int, cost_usd: float, unpriced_calls: int, cost_per_accepted_change_usd: float|null, runs_verified: int, first_attempt_passed: int, repairs_before_acceptance: float|null, reviewed: int, with_unexpected_changes: int, input_tokens: int, output_tokens: int, visual_edits: int, setup_cost_usd: float}
     */
    public function handle(Project $project): array
    {
        $requests = $project->featureRequests()->get();
        $runIds = Run::query()->whereIn('feature_request_id', $requests->modelKeys())->pluck('id');
        $calls = RunEvent::query()->whereIn('run_id', $runIds)->where('type', 'model_call')->get();

        $cost = 0.0;
        $unpriced = 0;

        foreach ($calls as $call) {
            $price = $call->data['cost_usd'] ?? null;

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

        return [
            'requests' => $requests->count(),
            'accepted' => $acceptedCount,
            'reverted' => $accepted->filter(fn (FeatureRequest $request) => $request->reverted_at !== null)->unique('commit_sha')->count(),
            'cost_usd' => round($cost, 4),
            'unpriced_calls' => $unpriced,
            'cost_per_accepted_change_usd' => $acceptedCount > 0 ? round($cost / $acceptedCount, 4) : null,
            'runs_verified' => $finished->count(),
            'first_attempt_passed' => $finished->filter(fn (Verification $verification) => in_array($verification->status, [VerificationStatus::Passed, VerificationStatus::Unverified], true))->count(),
            'repairs_before_acceptance' => $acceptedRuns->isEmpty() ? null : round((float) $acceptedRuns->avg('repairs'), 2),
            'reviewed' => $reviewed->count(),
            'with_unexpected_changes' => $reviewed->filter(fn (Run $run) => ($run->review['classification']['unexpected'] ?? []) !== [])->count(),
            'input_tokens' => (int) $calls->sum(fn (RunEvent $call) => (int) ($call->data['input_tokens'] ?? 0)),
            'output_tokens' => (int) $calls->sum(fn (RunEvent $call) => (int) ($call->data['output_tokens'] ?? 0)),
            'visual_edits' => $project->visualEdits()->count(),
            'setup_cost_usd' => round((float) collect($project->setup_model_calls ?? [])->sum(fn (array $call) => $call['cost_usd'] ?? 0), 4),
        ];
    }
}
