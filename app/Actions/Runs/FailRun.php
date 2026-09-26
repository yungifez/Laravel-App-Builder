<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Runs\RunLease;

class FailRun
{
    public function __construct(
        private TransitionRun $transitionRun,
        private DestroyWorkspace $destroyWorkspace,
    ) {}

    /**
     * Mark the run failed with a reason, fail a request that has no change
     * yet, and remove the run's workspace. The cause is a short code, such
     * as "worker_stopped", that failures are grouped by.
     */
    public function handle(Run $run, string $reason, ?RunLease $lease = null, string $cause = 'unknown'): void
    {
        $this->transitionRun->handle($run, RunStatus::Failed, $lease, ['error' => $reason], ['reason' => $cause]);

        $featureRequest = $run->featureRequest;

        if ($featureRequest->status === FeatureRequestStatus::Generating) {
            $featureRequest->update(['status' => FeatureRequestStatus::Failed, 'error' => $reason]);
        }

        if ($run->workspace !== null) {
            rescue(fn () => $this->destroyWorkspace->handle($run->workspace));
        }
    }
}
