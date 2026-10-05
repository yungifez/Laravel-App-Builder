<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
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
     * yet, and remove the run's workspace. The cause is why it stopped,
     * which failures are grouped by and the owner is told.
     */
    public function handle(Run $run, string $reason, StopReason $cause, ?RunLease $lease = null): void
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
