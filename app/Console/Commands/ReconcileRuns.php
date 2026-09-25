<?php

namespace App\Console\Commands;

use App\Actions\Features\RequestVerification;
use App\Actions\Runs\CompleteRunVerification;
use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('runs:reconcile')]
#[Description('Resume runs whose worker stopped and carry finished verifications back to their runs')]
class ReconcileRuns extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CompleteRunVerification $completeRunVerification, RequestVerification $requestVerification): int
    {
        $stale = now()->subSeconds((int) config('builder.construction.lease_seconds'));
        $resumed = 0;
        $settled = 0;

        Run::query()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing, RunStatus::Cancelling])
            ->where(fn (Builder $query) => $query
                ->where('lease_expires_at', '<', now())
                ->orWhere(fn (Builder $query) => $query->whereNull('lease_owner')->where('updated_at', '<', $stale)))
            ->each(function (Run $run) use (&$resumed) {
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

        $this->components->info("Resumed {$resumed} run(s); settled {$settled} verification(s).");

        return self::SUCCESS;
    }
}
