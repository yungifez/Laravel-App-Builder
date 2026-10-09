<?php

namespace App\Console\Commands;

use App\Actions\Publishing\CollectLiveErrors as Collect;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('publishing:collect-errors')]
#[Description('Read the errors each published app raised online since the last check')]
class CollectLiveErrors extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Collect $collect): int
    {
        $collected = 0;

        // Only the version that is online now: an older one's errors are
        // the past, and a newer one is not online yet.
        Deployment::query()
            ->where('status', DeploymentStatus::Published)
            ->whereIn('id', Deployment::query()->where('status', DeploymentStatus::Published)->selectRaw('max(id)')->groupBy('project_id'))
            ->with('project')
            ->each(function (Deployment $deployment) use ($collect, &$collected) {
                try {
                    $collected += $collect->handle($deployment) ?? 0;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->components->error("Could not read errors of deployment [{$deployment->id}]: {$exception->getMessage()}");
                }
            });

        $this->components->info("Collected {$collected} error(s).");

        return self::SUCCESS;
    }
}
