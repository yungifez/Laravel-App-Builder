<?php

namespace App\Actions\Runs;

use App\Enums\FeatureRequestStatus;
use App\Enums\NextStep;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Events\RunStatusChanged;
use App\Models\Run;
use App\Runs\Exceptions\InvalidRunTransition;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\RunLease;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransitionRun
{
    /**
     * Move the run to a new state, log it and announce it. A request with
     * no change made yet follows its run's stop.
     *
     * A worker passes its lease, and the move only happens while the lease
     * still holds the run. Once the owner has asked to cancel, the only move
     * left is to cancelled.
     *
     * @param  array<string, mixed>  $attributes  Other columns to update with the state
     * @param  array<string, mixed>  $details  Extra data for the event; a move to failed or needs_user_decision gives its StopReason as "reason"
     *
     * @throws LeaseLost
     * @throws RunCancelled
     * @throws InvalidRunTransition
     * @throws InvalidArgumentException when a run stops without a StopReason
     */
    public function handle(Run $run, RunStatus $to, ?RunLease $lease = null, array $attributes = [], array $details = []): Run
    {
        return DB::transaction(function () use ($run, $to, $lease, $attributes, $details) {
            $locked = Run::query()->lockForUpdate()->findOrFail($run->id);
            $from = $locked->status;

            $lease?->assertHeldOn($locked);

            if ($from === RunStatus::Cancelling && $to !== RunStatus::Cancelled) {
                throw RunCancelled::forRun($locked->id);
            }

            if (! $from->canTransitionTo($to)) {
                throw InvalidRunTransition::between($from, $to);
            }

            $locked->fill($attributes);
            $locked->status = $to;

            // Why the run failed or waits on its owner, for grouping and
            // filtering and for what the owner is told. It clears when the
            // run moves on, so it never names a stop the run has since left
            // behind. A stop always says why: there is no unknown one.
            $locked->stop_reason = match (true) {
                in_array($to, [RunStatus::Failed, RunStatus::NeedsUserDecision], true) => ($details['reason'] ?? null) instanceof StopReason
                    ? $details['reason']
                    : throw new InvalidArgumentException("A run that moves to {$to->value} needs a StopReason as its reason."),
                $to === RunStatus::Cancelled => StopReason::Cancelled,
                default => null,
            };

            if ($from === RunStatus::Queued) {
                $locked->started_at ??= now();
            }

            if ($to->finished()) {
                $locked->finished_at = now();

                // A worker has nothing left to do on a change that ended.
                $locked->tokens()->delete();

                $locked->lease_owner = null;
                $locked->lease_expires_at = null;
            } elseif ($lease !== null) {
                $locked->lease_expires_at = now()->addSeconds((int) config('builder.construction.lease_seconds'));
            }

            $locked->save();
            $this->followOnTheRequest($locked, $from, $to);
            $locked->recordEvent('status', ['from' => $from->value, 'to' => $to->value, ...$details]);

            $run->setRawAttributes($locked->getAttributes(), sync: true);

            // Sent once the transaction commits.
            RunStatusChanged::dispatch($run, $from, $to);

            return $run;
        });
    }

    /**
     * Keep the request's status with its run while no change is made yet: a
     * stop fails it, and the same run going on again makes it again. A stop
     * that waits for the owner's answer leaves it making the change.
     */
    protected function followOnTheRequest(Run $run, RunStatus $from, RunStatus $to): void
    {
        $featureRequest = $run->featureRequest;

        if ($featureRequest === null) {
            return;
        }

        if ($run->stop_reason !== null && $run->stop_reason !== StopReason::Cancelled
            && $run->stop_reason->nextStep() !== NextStep::Answer
            && $featureRequest->status === FeatureRequestStatus::Generating) {
            $featureRequest->update(['status' => FeatureRequestStatus::Failed, 'error' => $run->error]);
        } elseif ($from === RunStatus::NeedsUserDecision && ! $to->finished() && $to !== RunStatus::Cancelling
            && $featureRequest->status === FeatureRequestStatus::Failed) {
            $featureRequest->update(['status' => FeatureRequestStatus::Generating, 'error' => null]);
        }
    }
}
