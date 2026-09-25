<?php

namespace App\Actions\Runs;

use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\ExecuteRun;
use App\Models\Run;
use App\Models\Verification;
use App\Runs\ConstructionDriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompleteRunVerification
{
    public function __construct(
        private TransitionRun $transitionRun,
        private ConstructionDriverManager $drivers,
    ) {}

    /**
     * Carry a finished verification back to the run that asked for it.
     *
     * A passing (or unverified) change goes on to review. A failing change
     * goes back for a repair while the driver can repair and the run has
     * repairs left; otherwise the run stops for the owner's decision.
     */
    public function handle(Verification $verification): void
    {
        if ($verification->run_id === null || ! $verification->status->finished()) {
            return;
        }

        DB::transaction(function () use ($verification) {
            $run = Run::query()->lockForUpdate()->findOrFail($verification->run_id);

            if ($run->status !== RunStatus::Verifying || (int) $run->verifications()->max('id') !== $verification->id) {
                return;
            }

            $details = ['verification_id' => $verification->id, 'verification' => $verification->status->value];

            if (! in_array($verification->status, [VerificationStatus::Failed, VerificationStatus::Errored], true)) {
                $this->transitionRun->handle($run, RunStatus::Reviewing, details: $details);

                ExecuteRun::dispatch($run)->afterCommit();

                return;
            }

            if ($this->drivers->driver($run->driver)->canRepair() && $run->repairs < (int) config('builder.construction.budgets.repairs')) {
                $this->transitionRun->handle($run, RunStatus::Implementing, attributes: [
                    'repairs' => $run->repairs + 1,
                    'feedback' => ['reason' => 'verification_failed', 'details' => $this->failures($verification)],
                ], details: [...$details, 'reason' => 'verification_failed']);

                ExecuteRun::dispatch($run)->afterCommit();

                return;
            }

            $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, attributes: [
                'error' => __('Verification did not pass, and this run cannot repair the change.'),
            ], details: [...$details, 'reason' => 'verification_failed', 'choices' => ConstructRun::DECISION_CHOICES]);
        });
    }

    /**
     * Describe the verification's failing results for the next attempt.
     *
     * @return list<string>
     */
    protected function failures(Verification $verification): array
    {
        $failures = [];

        foreach ($verification->results ?? [] as $result) {
            if (in_array($result['outcome'], ['failed', 'errored'], true)) {
                $failures[] = "{$result['name']} {$result['outcome']}:\n".Str::substr($result['output'], -3000);
            }
        }

        return $failures ?: [__('Verification did not pass: :error', ['error' => $verification->error])];
    }
}
