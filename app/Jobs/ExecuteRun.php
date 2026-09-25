<?php

namespace App\Jobs;

use App\Actions\Runs\AcquireRunLease;
use App\Actions\Runs\ConstructRun;
use App\Actions\Runs\FailRun;
use App\Actions\Runs\ReleaseRunLease;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Runs\Exceptions\RunLeaseHeld;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ExecuteRun implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: preparation plus the run's time
     * budget. Keep the queue connection's retry_after above this value.
     */
    public int $timeout = 3600;

    /**
     * Unexpected errors are retried; the run resumes from its journal.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(public Run $run) {}

    /**
     * Claim the run and carry it forward.
     *
     * A duplicate delivery finds the run claimed or finished and leaves it
     * alone. A run whose worker died is picked up again by `runs:reconcile`
     * once its lease expires.
     */
    public function handle(AcquireRunLease $acquireRunLease, ConstructRun $constructRun, ReleaseRunLease $releaseRunLease): void
    {
        try {
            $lease = $acquireRunLease->handle($this->run, (string) Str::uuid());
        } catch (RunLeaseHeld) {
            return;
        }

        if ($lease === null) {
            return;
        }

        try {
            $constructRun->handle($this->run, $lease);
        } finally {
            $releaseRunLease->handle($lease);
        }
    }

    /**
     * Fail the run after its last attempt, unless another worker holds it.
     */
    public function failed(?Throwable $exception): void
    {
        $run = $this->run->fresh();

        if ($run === null || $run->hasActiveLease() || ! $run->status->canTransitionTo(RunStatus::Failed)) {
            return;
        }

        app(FailRun::class)->handle($run, __('The run stopped unexpectedly.'));
    }
}
