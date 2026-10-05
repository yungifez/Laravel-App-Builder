<?php

namespace App\Console\Commands;

use App\Actions\Features\RequestVerification;
use App\Actions\Runs\CompleteRunVerification;
use App\Actions\Runs\FailRun;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use App\Runs\Drivers\WorkerDriver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('runs:reconcile')]
#[Description('Resume runs whose worker stopped, stop runs whose outside worker never answered, and carry finished verifications back to their runs')]
class ReconcileRuns extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CompleteRunVerification $completeRunVerification, RequestVerification $requestVerification, WorkerDriver $workers, FailRun $failRun): int
    {
        $stale = now()->subSeconds((int) config('builder.construction.lease_seconds'));
        $resumed = 0;
        $settled = 0;
        $lapsed = 0;

        Run::query()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing, RunStatus::Reviewing, RunStatus::Cancelling])
            ->where(fn (Builder $query) => $query
                ->where('lease_expires_at', '<', now())
                ->orWhere(fn (Builder $query) => $query->whereNull('lease_owner')->where('updated_at', '<', $stale)))
            ->each(function (Run $run) use ($workers, $failRun, &$resumed, &$lapsed) {
                // A worker outside has the change; handing it back resumes the
                // run. Once its connection ran out with nothing handed back,
                // the run stops, so it does not wait forever.
                if ($run->driver === 'worker' && $run->status === RunStatus::Implementing && $run->workspace_id !== null && $workers->submission($run) === null) {
                    if ($this->connectionLapsed($run)) {
                        $failRun->handle($run, __('Your own coding tool did not hand this change back before its connection ran out, so I stopped it. Your app is as it was, and you can hand the change to your tool again.'), cause: StopReason::WorkerLapsed);
                        $lapsed++;
                    }

                    return;
                }

                ExecuteRun::dispatch($run);
                $resumed++;
            });

        Run::query()->where('status', RunStatus::Verifying)->each(function (Run $run) use ($completeRunVerification, $requestVerification, &$settled) {
            try {
                $verification = $run->verifications()->latest('id')->first();

                if ($verification === null) {
                    $requestVerification->handle($run->featureRequest, $run);
                } elseif ($verification->status->finished()) {
                    $completeRunVerification->handle($verification);
                    $settled++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->components->error("Could not reconcile run [{$run->id}]: {$exception->getMessage()}");
            }
        });

        $this->components->info("Resumed {$resumed} run(s); stopped {$lapsed} whose worker never answered; settled {$settled} verification(s).");

        return self::SUCCESS;
    }

    /**
     * Determine if the outside worker's connection to the run ran out. A
     * run that never had one counts from when it last changed.
     */
    protected function connectionLapsed(Run $run): bool
    {
        $token = $run->tokens()->latest('id')->first();

        if ($token === null) {
            return $run->updated_at->lt(now()->subMinutes((int) config('builder.agents.workers.minutes')));
        }

        return $token->expires_at?->isPast() ?? false;
    }
}
