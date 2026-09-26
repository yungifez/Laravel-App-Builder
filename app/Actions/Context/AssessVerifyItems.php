<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Features\PatchSummary;
use App\Features\TestReport;
use App\Runs\Plan;
use App\Runs\Review;

class AssessVerifyItems
{
    /**
     * Say how we know each of the brief's verify items (its acceptance
     * criteria) holds. The reviewer names a test for each; that claim is
     * held against what the test suite actually ran:
     *
     * - "tested": the named test is in a file the change adds or changes,
     *   and the suite's report shows it ran and passed.
     * - "not_run": the suite did not pass, or the named test failed.
     * - "not_run_by_checks": the suite never ran the named test, because
     *   its file is outside the suite's folders or the report does not show
     *   it passing (it is missing, skipped or misnamed).
     * - "claimed": the suite passed but reports no individual tests, so
     *   only the reviewer's word says the test ran. It is not evidence.
     * - "no_test": no test file in the change was named.
     *
     * @param  list<array{name: string, stage: string, outcome: string, tests?: list<array{file: string, name: string, outcome: string}>}>  $verificationResults
     * @return list<array{criterion: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}>
     */
    public function handle(Plan $plan, Review $review, string $patch, array $verificationResults): array
    {
        $suiteName = (string) config('builder.verification.suite_check');
        $suite = collect($verificationResults)->first(fn (array $result) => $result['name'] === $suiteName);
        $suitePassed = ($suite['outcome'] ?? null) === 'passed';
        $ran = $suite['tests'] ?? null;
        $diffs = array_column(PatchSummary::files($patch), 'diff', 'path');
        $claims = collect($review->verify)->keyBy('criterion');

        return array_map(function (string $criterion, int $index) use ($claims, $diffs, $suitePassed, $ran) {
            $claim = $claims->get($index + 1);
            $file = is_string($claim['test_file'] ?? null) ? ltrim($claim['test_file'], './') : null;
            $name = is_string($claim['test_name'] ?? null) ? $claim['test_name'] : null;
            $inChange = $file !== null && isset($diffs[$file]);
            $outcome = $inChange && $ran !== null && $name !== null ? TestReport::outcome($ran, $file, $name) : null;

            return [
                'criterion' => $criterion,
                'test_file' => $file,
                'test_name' => $name,
                'evidence' => match (true) {
                    ! $inChange => 'no_test',
                    ! Capability::runBySuite($file) => 'not_run_by_checks',
                    ! $suitePassed || $outcome === TestReport::FAILED => 'not_run',
                    $ran === null => 'claimed',
                    $outcome === TestReport::PASSED => 'tested',
                    default => 'not_run_by_checks',
                },
                'named_in_diff' => $inChange && $name !== null && str_contains($diffs[$file], $name),
            ];
        }, $plan->acceptanceCriteria, array_keys($plan->acceptanceCriteria));
    }
}
