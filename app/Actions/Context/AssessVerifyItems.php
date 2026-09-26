<?php

namespace App\Actions\Context;

use App\Features\PatchSummary;
use App\Runs\Plan;
use App\Runs\Review;

class AssessVerifyItems
{
    /**
     * Say how we know each of the brief's verify items (its acceptance
     * criteria) holds. It is "tested" when the reviewer named a test file
     * that the change adds or changes and the test suite passed, "not_run"
     * when that file is in the change but the suite did not pass, and
     * "no_test" when no such file was named or it is not in the change.
     * The reviewer's word alone is never counted as evidence.
     *
     * @param  list<array{name: string, stage: string, outcome: string}>  $verificationResults
     * @return list<array{criterion: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}>
     */
    public function handle(Plan $plan, Review $review, string $patch, array $verificationResults): array
    {
        $suite = (string) config('builder.verification.suite_check');
        $suitePassed = collect($verificationResults)->contains(fn (array $result) => $result['name'] === $suite && $result['outcome'] === 'passed');
        $diffs = array_column(PatchSummary::files($patch), 'diff', 'path');
        $claims = collect($review->verify)->keyBy('criterion');

        return array_map(function (string $criterion, int $index) use ($claims, $diffs, $suitePassed) {
            $claim = $claims->get($index + 1);
            $file = is_string($claim['test_file'] ?? null) ? ltrim($claim['test_file'], './') : null;
            $name = is_string($claim['test_name'] ?? null) ? $claim['test_name'] : null;
            $inChange = $file !== null && isset($diffs[$file]);

            return [
                'criterion' => $criterion,
                'test_file' => $file,
                'test_name' => $name,
                'evidence' => match (true) {
                    $inChange && $suitePassed => 'tested',
                    $inChange => 'not_run',
                    default => 'no_test',
                },
                'named_in_diff' => $inChange && $name !== null && str_contains($diffs[$file], $name),
            ];
        }, $plan->acceptanceCriteria, array_keys($plan->acceptanceCriteria));
    }
}
