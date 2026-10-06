<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Run;
use Illuminate\Support\Facades\DB;

class CancelRun
{
    public function __construct(
        private TransitionRun $transitionRun,
        private DestroyWorkspace $destroyWorkspace,
        private ExtractCandidateChange $extractCandidateChange,
    ) {}

    /**
     * Ask the run to stop.
     *
     * A worker holding the lease stops at its next tool call or state change
     * and finishes the cancellation; otherwise it is finished here.
     */
    public function handle(Run $run): void
    {
        $finishNow = DB::transaction(function () use ($run) {
            $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status->finished() || $locked->status === RunStatus::Cancelling) {
                return false;
            }

            $this->transitionRun->handle($locked, RunStatus::Cancelling);

            return ! $locked->hasActiveLease();
        });

        if ($finishNow) {
            $this->finish($run);
        }
    }

    /**
     * Complete a cancellation: mark the run cancelled, keep the code it made
     * so far, and remove its workspace.
     */
    public function finish(Run $run): void
    {
        $run->refresh();

        if ($run->status !== RunStatus::Cancelling) {
            return;
        }

        $this->transitionRun->handle($run, RunStatus::Cancelled);

        $featureRequest = $run->featureRequest;

        if ($featureRequest->status === FeatureRequestStatus::Generating) {
            $featureRequest->update(['status' => FeatureRequestStatus::Cancelled, 'error' => __('The run was cancelled.')]);
        }

        if ($run->workspace !== null) {
            // Kept so the owner can go on from it (see KeepTryingRun) instead
            // of paying to build it again. A copy that cannot be read is
            // simply not kept.
            if ($run->workspace->status === WorkspaceStatus::Ready && $run->workspace->baseline_commit !== null && $featureRequest->commit_sha === null) {
                $madeSoFar = rescue(fn () => $this->extractCandidateChange->handle($run->workspace), report: false);

                if (is_string($madeSoFar) && trim($madeSoFar) !== '') {
                    $featureRequest->update(['patch' => $madeSoFar]);
                }
            }

            rescue(fn () => $this->destroyWorkspace->handle($run->workspace));
        }
    }
}
