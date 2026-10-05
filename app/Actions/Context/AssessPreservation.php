<?php

namespace App\Actions\Context;

use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Features\TestReport;
use App\Runs\Plan;

class AssessPreservation
{
    /**
     * Say how we know each thing the brief said to keep as it is. It is
     * "verified" when the suite passed and its report shows the area's own
     * tests ran and passed, "untouched" when no file the area claims
     * changed, and "not checked" otherwise. A reviewer's opinion is never counted as evidence, and
     * neither is a test the change itself rewrote: it proves what the code
     * does now, not that what was there before still holds.
     *
     * @param  list<array{name: string, stage: string, outcome: string, tests?: list<array{file: string, name: string, outcome: string}>}>  $verificationResults
     * @return list<array{area: string|null, statement: string, evidence: string, unchanged: bool, tests: int}>
     */
    public function handle(Plan $plan, ChangeClassification $classification, ProjectContext $context, array $verificationResults): array
    {
        $suiteName = (string) config('builder.verification.suite_check');
        $suite = collect($verificationResults)->first(fn (array $result) => $result['name'] === $suiteName);
        $suitePassed = ($suite['outcome'] ?? null) === 'passed';
        // Without a report of each test, nothing shows which tests ran.
        $ran = $suite['tests'] ?? [];
        $touched = $classification->touched();
        $changed = $classification->changedFiles();

        return array_map(function (array $item) use ($context, $suitePassed, $ran, $touched, $changed) {
            $capability = $item['area'] !== null ? ($context->capabilities[$item['area']] ?? null) : null;
            $unchanged = $capability !== null && ! in_array($capability->key, $touched, true);
            $tests = $capability === null ? 0 : count(array_filter(
                array_diff($capability->testFiles, $changed),
                fn (string $file) => TestReport::filePassed($ran, $file),
            ));

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
