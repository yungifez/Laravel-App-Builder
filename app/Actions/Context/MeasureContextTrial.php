<?php

namespace App\Actions\Context;

use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\RunEvent;
use App\Models\Verification;

class MeasureContextTrial
{
    /**
     * Measure what one trial's change took, from what its runs recorded
     * (architecture §26.7): whether its first checks passed, whether its
     * latest did, the tokens and cost of its model calls, the agent's tool
     * calls, its minutes, its repairs and the areas it changed unasked.
     * Also return the ways its agents were given context, to tell whether
     * the trial got the one it asked for.
     *
     * @return array{measures: array{first_attempt_passed: bool|null, verified: bool, tokens: int, cost_usd: float, unpriced_calls: int, tool_calls: int, minutes: float|null, repairs: int, unexpected_areas: int|null}, modes: list<string>}
     */
    public function handle(FeatureRequest $request): array
    {
        $runs = $request->runs()->oldest('id')->get();
        $events = RunEvent::query()->whereIn('run_id', $runs->modelKeys())->whereIn('type', ['model_call', 'context_compiled'])->get();
        $calls = $events->where('type', 'model_call');
        $verifications = Verification::query()->whereIn('run_id', $runs->modelKeys())->oldest('id')->get();
        $first = $verifications->first(fn (Verification $verification) => $verification->status->finished());
        $latest = $verifications->last(fn (Verification $verification) => $verification->status->finished());
        $started = $runs->min('started_at');
        $finished = $runs->max('finished_at');
        $review = $runs->last()?->review;

        return [
            'measures' => [
                'first_attempt_passed' => $first === null ? null : $first->status === VerificationStatus::Passed,
                'verified' => $latest?->status === VerificationStatus::Passed,
                'tokens' => (int) $calls->sum(fn (RunEvent $call) => (int) ($call->data['input_tokens'] ?? 0) + (int) ($call->data['output_tokens'] ?? 0)),
                'cost_usd' => round((float) $calls->sum(fn (RunEvent $call) => is_numeric($call->data['cost_usd'] ?? null) ? (float) $call->data['cost_usd'] : 0.0), 4),
                'unpriced_calls' => $calls->filter(fn (RunEvent $call) => ! is_numeric($call->data['cost_usd'] ?? null))->count(),
                'tool_calls' => (int) $calls->sum(fn (RunEvent $call) => (int) ($call->data['tool_calls'] ?? 0)),
                'minutes' => $started === null || $finished === null ? null : round($started->diffInSeconds($finished) / 60, 2),
                'repairs' => (int) $runs->sum('repairs'),
                'unexpected_areas' => $review === null ? null : count($review['classification']['unexpected']),
            ],
            'modes' => array_values(array_unique($events->where('type', 'context_compiled')->map(fn (RunEvent $event) => (string) $event->data['mode'])->all())),
        ];
    }
}
