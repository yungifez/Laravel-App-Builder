<?php

namespace App\Actions\Runs;

use App\Models\Run;
use App\Runs\RunLease;
use Illuminate\Support\Facades\DB;

class ReleaseRunLease
{
    /**
     * Give up the lease if it still holds the run. The fencing token is kept,
     * so the next holder's token is always higher.
     */
    public function handle(RunLease $lease): void
    {
        DB::transaction(function () use ($lease) {
            $run = Run::query()->lockForUpdate()->find($lease->runId);

            if ($run !== null && $lease->isHeldOn($run)) {
                $run->update(['lease_owner' => null, 'lease_expires_at' => null]);
            }
        });
    }
}
