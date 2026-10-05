<?php

namespace App\Actions\Runs;

use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\WorkspaceStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Validation\ValidationException;

class KeepTryingRun
{
    /**
     * Stops the owner can ask the change to keep working past: it ran out
     * of attempts to fix what the checks or the second look found, or of
     * turns or time while it built the change.
     */
    public const STOPS = [StopReason::VerificationFailed, StopReason::ReviewFindings, StopReason::BudgetExhausted];

    public function __construct(private TransitionRun $transitionRun) {}

    /**
     * Determine if the change stopped only because it ran out of attempts,
     * with its work so far still there to go on from.
     */
    public static function possible(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        return $run !== null
            && $run->status === RunStatus::NeedsUserDecision
            && $run->question === null
            && in_array($run->stop_reason, self::STOPS, true)
            && self::feedback($run) !== null
            && $run->plan !== null
            // Its work so far must still be there to build on.
            && $run->workspace !== null
            && $run->workspace->status !== WorkspaceStatus::Destroyed;
    }

    /**
     * Get what the next pass must do: what was kept when the change stopped,
     * or, for a change that stopped before that was kept, the same worked
     * out again from its last checks or review. Feedback from an earlier
     * repair says nothing about why it stopped, so it is not used.
     *
     * @return array{reason: string, details: list<string>}|null
     */
    protected static function feedback(Run $run): ?array
    {
        if (($run->feedback['reason'] ?? null) === $run->stop_reason?->value) {
            return $run->feedback;
        }

        $details = match ($run->stop_reason) {
            StopReason::VerificationFailed => ($verification = $run->verifications()->reorder('id', 'desc')->first()) === null
                ? []
                : app(CompleteRunVerification::class)->failures($verification),
            StopReason::ReviewFindings => array_values(array_map(
                fn (array $finding) => trim(($finding['file'] !== null ? "{$finding['file']}: " : '').$finding['summary']),
                array_filter($run->review['findings'] ?? [], fn (array $finding) => $finding['severity'] === 'blocking'),
            )),
            StopReason::BudgetExhausted => [__('You stopped before you finished. Finish the change.')],
            default => [],
        };

        return $details === [] || $run->stop_reason === null ? null : ['reason' => $run->stop_reason->value, 'details' => $details];
    }

    /**
     * Go on from the work so far and fix what stopped it, with as many
     * attempts, turns and time again as it had at the start. The owner decides when a change
     * is worth more work, not a fixed limit.
     *
     * @throws ValidationException when the change did not stop that way.
     */
    public function handle(FeatureRequest $featureRequest): Run
    {
        if (! self::possible($featureRequest)) {
            throw ValidationException::withMessages(['keep_trying' => __('This change did not stop for want of tries, so there is nothing to keep trying.')]);
        }

        $run = $featureRequest->latestRun;

        $this->transitionRun->handle($run, RunStatus::Implementing, attributes: [
            'repairs' => $run->repairs + 1,
            'feedback' => self::feedback($run),
            'error' => null,
        ], details: ['reason' => 'kept_trying', 'stopped' => $run->stop_reason?->value]);

        ExecuteRun::dispatch($run);

        return $run;
    }
}
