<?php

namespace App\Runs;

use App\Models\Run;
use App\Runs\Exceptions\LeaseLost;

/**
 * A worker's claim on a run, identified by its fencing token.
 */
final readonly class RunLease
{
    public function __construct(
        public int $runId,
        public string $owner,
        public int $fencingToken,
    ) {}

    /**
     * Determine if this lease still holds the run (read under the run's row lock).
     */
    public function isHeldOn(Run $run): bool
    {
        return $run->id === $this->runId
            && $run->fencing_token === $this->fencingToken
            && $run->lease_owner === $this->owner
            && $run->hasActiveLease();
    }

    /**
     * Stop the caller unless this lease still holds the run.
     *
     * @throws LeaseLost
     */
    public function assertHeldOn(Run $run): void
    {
        if (! $this->isHeldOn($run)) {
            throw LeaseLost::forRun($this->runId, $this->fencingToken);
        }
    }
}
