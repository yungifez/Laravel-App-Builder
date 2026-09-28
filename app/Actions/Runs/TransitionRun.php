<?php

namespace App\Actions\Runs;

use App\Enums\RunStatus;
use App\Events\RunStatusChanged;
use App\Models\Run;
use App\Runs\Exceptions\InvalidRunTransition;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\RunLease;
use Illuminate\Support\Facades\DB;

class TransitionRun
{
    /**
     * Move the run to a new state, log it and announce it.
     *
     * A worker passes its lease, and the move only happens while the lease
     * still holds the run. Once the owner has asked to cancel, the only move
     * left is to cancelled.
     *
     * @param  array<string, mixed>  $attributes  Other columns to update with the state
     * @param  array<string, mixed>  $details  Extra data for the event
     *
     * @throws LeaseLost
     * @throws RunCancelled
     * @throws InvalidRunTransition
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
            // filtering. It clears when the run moves on, so it never names
            // a stop the run has since left behind.
            $locked->stop_reason = in_array($to, [RunStatus::Failed, RunStatus::NeedsUserDecision], true)
                ? (is_string($details['reason'] ?? null) ? $details['reason'] : 'unknown')
                : ($to === RunStatus::Cancelled ? 'cancelled' : null);

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
            $locked->recordEvent('status', ['from' => $from->value, 'to' => $to->value, ...$details]);

            $run->setRawAttributes($locked->getAttributes(), sync: true);

            // Sent once the transaction commits.
            RunStatusChanged::dispatch($run, $from, $to);

            return $run;
        });
    }
}
