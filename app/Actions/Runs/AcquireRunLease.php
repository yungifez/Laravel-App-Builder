<?php

namespace App\Actions\Runs;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Runs\Exceptions\RunLeaseHeld;
use App\Runs\RunLease;
use Illuminate\Support\Facades\DB;

class AcquireRunLease
{
    /**
     * Claim the run for a worker, with a new fencing token.
     *
     * Returns null when there is nothing for a worker to do (the run has
     * finished or is waiting on verification or the owner), so a duplicate
     * delivery exits without touching it.
     *
     * @throws RunLeaseHeld when another worker holds an unexpired lease.
     */
    public function handle(Run $run, string $owner): ?RunLease
    {
        return DB::transaction(function () use ($run, $owner) {
            $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

            if (! $locked->status->isWorkerOwned() && $locked->status !== RunStatus::Cancelling) {
                return null;
            }

            if ($locked->hasActiveLease() && $locked->lease_owner !== $owner) {
                throw new RunLeaseHeld($locked->id, max(1, (int) now()->diffInSeconds($locked->lease_expires_at, absolute: true)));
            }

            $tookOver = $locked->lease_owner !== null;

            $locked->update([
                'fencing_token' => $locked->fencing_token + 1,
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds((int) config('builder.construction.lease_seconds')),
            ]);

            $locked->recordEvent('lease_acquired', ['fencing_token' => $locked->fencing_token, 'took_over' => $tookOver]);

            $run->setRawAttributes($locked->getAttributes(), sync: true);

            return new RunLease($locked->id, $owner, $locked->fencing_token);
        });
    }
}
