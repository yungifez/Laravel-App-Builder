<?php

namespace App\Console\Commands;

use App\Enums\DeploymentStatus;
use App\Jobs\ConfirmDeployment;
use App\Jobs\PublishDeployment;
use App\Models\Deployment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('publishing:reconcile')]
#[Description('End publishes whose job stopped part way, so each one offers its owner a next step')]
class ReconcileDeployments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) config('builder.publishing.stalled_minutes');
        $ended = 0;

        // A worker that died mid-publish leaves the publish where it was,
        // and the owner sees it working for ever. Past the limit no job is
        // still at it, so each ends the way its own job ends on a failure.
        Deployment::query()
            ->whereIn('status', [DeploymentStatus::Checking, DeploymentStatus::Pushing, DeploymentStatus::Confirming])
            ->where('updated_at', '<', now()->subMinutes($limit))
            ->each(function (Deployment $deployment) use ($limit, &$ended) {
                $stalled = new RuntimeException("Nothing changed on this publish for {$limit} minutes while it was {$deployment->status->value}.");

                // Sent already: checking again is the step. Not yet sent, or
                // part way through sending: trying again is.
                $deployment->status === DeploymentStatus::Confirming
                    ? (new ConfirmDeployment($deployment))->failed($stalled)
                    : (new PublishDeployment($deployment))->failed($stalled);
                $ended++;
            });

        $this->components->info("Ended {$ended} publish(es) that stopped part way.");

        return self::SUCCESS;
    }
}
