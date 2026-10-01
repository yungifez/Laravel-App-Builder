<?php

namespace App\Actions\Runs;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Validation\ValidationException;

class KeepTryingRun
{
    /**
     * Stops the owner can ask the change to keep working past: it ran out
     * of attempts to fix what the checks or the second look found.
     */
    public const STOPS = ['verification_failed', 'review_findings'];

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
            && $run->feedback !== null
            && $run->workspace_id !== null;
    }

    /**
     * Go on from the work so far and fix what stopped it, with as many
     * attempts again as it had at the start. The owner decides when a change
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
            'error' => null,
        ], details: ['reason' => 'kept_trying', 'stopped' => $run->stop_reason]);

        ExecuteRun::dispatch($run);

        return $run;
    }
}
