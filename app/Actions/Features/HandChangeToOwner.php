<?php

namespace App\Actions\Features;

use App\Actions\Runs\CancelRun;
use App\Actions\Runs\GrantWorkerAccess;
use App\Actions\Runs\KeepTryingRun;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Let the owner write a change with their own Claude Code or Codex
 * (architecture §11, "Workers"). We still plan the change, and check and
 * review what their worker hands back; only the writing moves to them.
 */
class HandChangeToOwner
{
    public function __construct(
        private CancelRun $cancelRun,
        private RetryFeatureRequest $retryFeatureRequest,
        private GrantWorkerAccess $grantWorkerAccess,
        private KeepTryingRun $keepTryingRun,
    ) {}

    /**
     * Determine if the owner can take the change over: we are making it,
     * it waits for their worker already, or it stopped.
     */
    public static function available(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        return self::theirs($run)
            || in_array($run?->status, [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing], true)
            || RetryFeatureRequest::retryable($featureRequest);
    }

    /**
     * Determine if the owner's worker writes the run, and it is still open.
     */
    public static function theirs(?Run $run): bool
    {
        return $run?->driver === 'worker'
            && ! $run->status->finished()
            && $run->status !== RunStatus::Cancelling;
    }

    /**
     * Determine if the change has a plan their tool can write from, on the
     * app as it was when it was planned.
     */
    protected static function planned(FeatureRequest $featureRequest): bool
    {
        $plan = $featureRequest->latestRun?->plan;

        return $plan !== null
            && ($plan['answer'] ?? null) === null
            && $featureRequest->commit_sha === null
            && $featureRequest->reverted_at === null
            && ! RetryFeatureRequest::mustBeMadeAgain($featureRequest);
    }

    /**
     * Give the change to the owner's worker, and make a new connection for
     * it. A change still being planned keeps its plan and the owner's
     * answers, and their worker writes it; a planned change we are writing,
     * or one that stopped, goes to their worker from its plan. Only a
     * change with no plan to go on from starts again for their worker. A new connection closes the
     * earlier one, so only one worker writes.
     *
     * @return array{change: FeatureRequest, run: Run, token: string}
     *
     * @throws ValidationException when the change is made or waits for an answer.
     */
    public function handle(FeatureRequest $featureRequest, User $owner): array
    {
        if (! self::available($featureRequest)) {
            throw ValidationException::withMessages([
                'worker' => __('This change cannot be handed over now. Answer its question, or ask for a new change.'),
            ]);
        }

        $run = $featureRequest->latestRun;

        // Planning reads the driver again before the writing starts, so
        // the switch is only safe until then: checked in the update itself.
        if ($run !== null && ! self::theirs($run) && Run::query()->whereKey($run->id)
            ->whereIn('status', [RunStatus::Queued, RunStatus::Planning])
            ->update(['driver' => 'worker']) === 1) {
            $run->refresh()->recordEvent('handed_to_owner', ['driver' => 'worker']);
        }

        if (! self::theirs($run) && self::planned($featureRequest)) {
            if (! $run->status->finished()) {
                $this->cancelRun->handle($run);
                // Stopping it may have marked the change stopped.
                $featureRequest->refresh();
            }

            // Their tool writes it from the plan and the owner's answers, so
            // nothing is planned or asked again.
            $run = $this->keepTryingRun->toOwner($featureRequest);
        }

        if (! self::theirs($run)) {
            if ($run !== null && ! $run->status->finished()) {
                $this->cancelRun->handle($run);
            }

            // The new run is queued only when this commits, so our own agent
            // never starts on it.
            [$featureRequest, $run] = DB::transaction(function () use ($featureRequest, $owner) {
                $change = $this->retryFeatureRequest->rebuild($featureRequest, $owner);
                $run = $change->latestRun()->firstOrFail();

                $run->update(['driver' => 'worker']);
                $run->recordEvent('handed_to_owner', ['driver' => 'worker']);

                return [$change, $run];
            });
        }

        $run->tokens()->delete();

        return [
            'change' => $featureRequest,
            'run' => $run,
            'token' => $this->grantWorkerAccess->handle($run),
        ];
    }
}
