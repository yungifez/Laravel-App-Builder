<?php

namespace App\Actions\Context;

use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Runs\Plan;

class AssessPreservation
{
    /**
     * Say how we know each thing the brief said to keep as it is. It is
     * "verified" when the area has its own tests and the test suite passed,
     * "untouched" when no file the area claims changed, and "not checked"
     * otherwise. A reviewer's opinion is never counted as evidence.
     *
     * @param  list<array{name: string, stage: string, outcome: string}>  $verificationResults
     * @return list<array{area: string|null, statement: string, evidence: string, unchanged: bool, tests: int}>
     */
    public function handle(Plan $plan, ChangeClassification $classification, ProjectContext $context, array $verificationResults): array
    {
        $suite = (string) config('builder.verification.suite_check');
        $suitePassed = collect($verificationResults)->contains(fn (array $result) => $result['name'] === $suite && $result['outcome'] === 'passed');
        $touched = $classification->touched();

        return array_map(function (array $item) use ($context, $suitePassed, $touched) {
            $capability = $item['area'] !== null ? ($context->capabilities[$item['area']] ?? null) : null;
            $unchanged = $capability !== null && ! in_array($capability->key, $touched, true);
            $tests = $capability === null ? 0 : count($capability->testFiles);

            $evidence = match (true) {
                $tests > 0 && $suitePassed => 'verified',
                $unchanged => 'untouched',
                default => 'not_checked',
            };

            return [
                'area' => $capability?->key,
                'statement' => $item['statement'],
                'evidence' => $evidence,
                'unchanged' => $unchanged,
                'tests' => $tests,
            ];
        }, $plan->preserve);
    }
}
