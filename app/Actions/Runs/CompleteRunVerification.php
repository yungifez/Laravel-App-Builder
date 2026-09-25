<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\DestroyWorkspace;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\Run;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;

class CompleteRunVerification
{
    public function __construct(
        private TransitionRun $transitionRun,
        private DestroyWorkspace $destroyWorkspace,
    ) {}

    /**
     * Carry a finished verification back to the run that asked for it.
     *
     * A passing (or unverified) change goes through review and completes the
     * run. The scripted review only accepts the verification's evidence; the
     * independent model reviewer replaces it. A failing change stops the run
     * for the owner's decision, since the scripted driver cannot repair it.
     */
    public function handle(Verification $verification): void
    {
        if ($verification->run_id === null || ! $verification->status->finished()) {
            return;
        }

        $completed = DB::transaction(function () use ($verification) {
            $run = Run::query()->lockForUpdate()->findOrFail($verification->run_id);

            if ($run->status !== RunStatus::Verifying || (int) $run->verifications()->max('id') !== $verification->id) {
                return null;
            }

            $details = ['verification_id' => $verification->id, 'verification' => $verification->status->value];

            if (in_array($verification->status, [VerificationStatus::Failed, VerificationStatus::Errored], true)) {
                $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, attributes: [
                    'error' => __('Verification did not pass, and this run cannot repair the change.'),
                ], details: [...$details, 'reason' => 'verification_failed', 'choices' => ConstructRun::DECISION_CHOICES]);

                return null;
            }

            $this->transitionRun->handle($run, RunStatus::Reviewing, details: $details);
            $run->recordEvent('review', ['reviewer' => 'scripted', 'approved' => true, ...$details]);
            $this->transitionRun->handle($run, RunStatus::Completed);

            return $run;
        });

        if ($completed?->workspace !== null) {
            rescue(fn () => $this->destroyWorkspace->handle($completed->workspace));
        }
    }
}
