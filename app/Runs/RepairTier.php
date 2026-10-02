<?php

namespace App\Runs;

use App\Features\TestReport;
use App\Jobs\VerifyFeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Verification;
use Illuminate\Support\Facades\Config;

/**
 * Decide whether a repair goes to the coding agent's light model (§9, §11):
 * only when the checks sent back one problem that a check can judge again,
 * such as one failing test, one static analysis error or a formatting
 * failure. The check decides whether the repair worked, so a cheaper model
 * is safe there, and a wrong answer costs one more pass. After a light
 * repair that did not pass, the next one goes to the usual model.
 */
class RepairTier
{
    public function light(Run $run): bool
    {
        if (! Config::boolean('builder.agents.light_repairs') || ($run->feedback['reason'] ?? null) !== 'verification_failed') {
            return false;
        }

        $previous = $run->events()->where('type', 'model_call')->where('data->role', 'coder')->reorder('sequence', 'desc')->first();

        if ($previous instanceof RunEvent && ($previous->data['light'] ?? false) === true) {
            return false;
        }

        $verification = $run->verifications()->latest('id')->first();

        return $verification instanceof Verification && $this->oneProblem($verification);
    }

    /**
     * Determine if the checks found exactly one new problem that its check
     * can judge.
     */
    protected function oneProblem(Verification $verification): bool
    {
        $failing = array_values(array_filter(
            $verification->results ?? [],
            fn (array $result) => in_array($result['outcome'], [VerifyFeatureRequest::OUTCOME_FAILED, VerifyFeatureRequest::OUTCOME_ERRORED], true)
                && ! (($result['at_start'] ?? null) === VerifyFeatureRequest::OUTCOME_FAILED && ($result['new_problems'] ?? []) === []),
        ));

        if (count($failing) !== 1 || $failing[0]['timed_out']) {
            return false;
        }

        $result = $failing[0];

        // A check that failed before the change too names only its new problems.
        if (($result['at_start'] ?? null) === VerifyFeatureRequest::OUTCOME_FAILED) {
            return count($result['new_problems'] ?? []) === 1;
        }

        if (isset($result['tests'])) {
            return collect($result['tests'])->where('outcome', TestReport::FAILED)->count() === 1;
        }

        $judge = collect(Config::array('builder.verification.checks'))->firstWhere('name', $result['name'])['light_repair'] ?? null;

        return $judge === true || (is_string($judge) && preg_match($judge, $result['output']) === 1);
    }
}
