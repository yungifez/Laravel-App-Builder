<?php

namespace App\Actions\Runs;

use App\Actions\Features\RequestVerification;
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
        private RequestVerification $requestVerification,
    ) {}

    /**
     * Carry a finished verification back to the run that asked for it.
     *
     * Checks stopped by a problem on our side run again, a few times,
     * without the coder hearing of it. A passing (or unverified) change
     * goes on to review, and so does one whose only problems were already
     * in the app before it. Otherwise the change goes back for a repair,
     * with only the problems it brought, while the driver can repair and
     * the run has repairs left; else the run stops for the owner's
     * decision.
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

            if ($verification->interrupted) {
                $this->retry($run, $details);

                return;
            }

            $failures = $this->failures($verification);

            if (! in_array($verification->status, [VerificationStatus::Failed, VerificationStatus::Errored], true) || $failures === []) {
                $this->transitionRun->handle($run, RunStatus::Reviewing, details: $failures === [] && $verification->status === VerificationStatus::Failed ? [...$details, 'reason' => 'failed_before'] : $details);

                ExecuteRun::dispatch($run)->afterCommit();

                return;
            }

            if ($this->drivers->driver($run->driver)->canRepair() && $run->repairs < $run->repairLimit()) {
                $this->transitionRun->handle($run, RunStatus::Implementing, attributes: [
                    'repairs' => $run->repairs + 1,
                    'feedback' => ['reason' => 'verification_failed', 'details' => $failures],
                ], details: [...$details, 'reason' => 'verification_failed']);

                ExecuteRun::dispatch($run)->afterCommit();

                return;
            }

            $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, attributes: [
                'error' => __('Verification did not pass, and this run cannot repair the change.'),
                // Kept so the owner can ask it to keep trying from here.
                'feedback' => ['reason' => 'verification_failed', 'details' => $failures],
            ], details: [...$details, 'reason' => 'verification_failed', 'choices' => ConstructRun::DECISION_CHOICES]);
        });
    }

    /**
     * Check the change again after a problem on our side stopped its
     * checks, until the retries run out; then stop the run and say whose
     * fault it is. None of this counts as a repair.
     *
     * @param  array<string, mixed>  $details
     */
    protected function retry(Run $run, array $details): void
    {
        $interrupted = 0;

        foreach ($run->verifications()->reorder('id', 'desc')->pluck('interrupted') as $stopped) {
            if (! $stopped) {
                break;
            }

            $interrupted++;
        }

        if ($interrupted <= (int) config('builder.verification.retries')) {
            $run->recordEvent('verification_retried', $details);
            $this->requestVerification->handle($run->featureRequest, $run);

            return;
        }

        $this->transitionRun->handle($run, RunStatus::NeedsUserDecision, attributes: [
            'error' => __('The checks could not run because of a problem on our side. This is our fault.'),
        ], details: [...$details, 'reason' => 'verification_interrupted', 'choices' => ConstructRun::DECISION_CHOICES]);
    }

    /**
     * Describe the problems the change brought, for the next attempt. A
     * check that failed the same way before the change is left out; for
     * one that already failed, only its new problems are given.
     *
     * @return list<string>
     */
    public function failures(Verification $verification): array
    {
        $failures = [];
        $failedBefore = 0;

        foreach ($verification->results ?? [] as $result) {
            if (! in_array($result['outcome'], ['failed', 'errored'], true)) {
                continue;
            }

            if (($result['at_start'] ?? null) === 'failed' && ($result['new_problems'] ?? []) === []) {
                $failedBefore++;
            } elseif (($result['at_start'] ?? null) !== 'failed') {
                $failures[] = "{$result['name']} {$result['outcome']}:\n".Str::substr($result['output'], -3000);
            } else {
                $failures[] = __(':check also fails without your change. These problems are new with it:', ['check' => $result['name']])."\n- ".implode("\n- ", $result['new_problems'] ?? []);
            }
        }

        // Nothing says what went wrong, so it cannot be put down to the app.
        if ($failures === [] && ($failedBefore === 0 || $verification->status === VerificationStatus::Errored)) {
            return [__('Verification did not pass: :error', ['error' => $verification->error])];
        }

        return $failures;
    }
}
