<?php

namespace App\Actions\Context;

use App\Context\Capability;
use App\Features\PatchSummary;
use App\Features\TestRefusals;
use App\Features\TestReport;
use App\Runs\Plan;
use App\Runs\Review;
use Illuminate\Support\Str;

class AssessVerifyItems
{
    /**
     * Say how we know each of the brief's verify items holds: each case
     * (base, alternate, exception) of each acceptance criterion. When the
     * tests were written from the plan before the change, each item's test
     * is the one written for it; otherwise the reviewer names one. Either
     * way, it is held against what the test suite actually ran:
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
     * A test that passed is then held against what running it showed:
     *
     * - "passes_without_change": it is a new test that also passes with the
     *   change taken out, so it does not show the change works.
     * - "no_request": an exception case whose test sent the app nothing
     *   while its requests were recorded, so no refusal could be seen.
     * - "not_refused": an exception case whose test's requests the app
     *   all let through.
     *
     * @param  list<array{name: string, stage: string, outcome: string, tests?: list<array{file: string, name: string, outcome: string}>}>  $verificationResults
     * @param  array<string, mixed>  $evidence  What the verification measured
     * @return list<array{criterion: string, kind: string, case: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}>
     */
    public function handle(Plan $plan, Review $review, string $patch, array $verificationResults, array $evidence = []): array
    {
        $suiteName = (string) config('builder.verification.suite_check');
        $suite = collect($verificationResults)->first(fn (array $result) => $result['name'] === $suiteName);
        $suitePassed = ($suite['outcome'] ?? null) === 'passed';
        $ran = $suite['tests'] ?? null;
        $diffs = array_column(PatchSummary::files($patch), 'diff', 'path');
        // Written before the change, the test for each item is known; the
        // reviewer's word is needed only when the coder wrote the tests.
        $claims = $plan->writtenTests !== []
            ? collect($plan->writtenTests)->mapWithKeys(fn (array $test) => [$test['item'] => ['test_file' => $test['file'], 'test_name' => $test['name']]])
            : collect($review->verify)->keyBy('criterion');
        /** @var list<array{file: string, name: string, without_change: string}> $newTests */
        $newTests = is_array($evidence['new_tests'] ?? null) ? $evidence['new_tests'] : [];
        /** @var array<string, bool>|null $refusals */
        $refusals = is_array($evidence['refusals'] ?? null) ? $evidence['refusals'] : null;

        return array_map(function (array $item, int $index) use ($claims, $diffs, $suitePassed, $ran, $newTests, $refusals) {
            $claim = $claims->get($index + 1);
            $file = is_string($claim['test_file'] ?? null) ? Str::chopStart(ltrim($claim['test_file'], '/'), './') : null;
            $name = is_string($claim['test_name'] ?? null) ? $claim['test_name'] : null;
            $inChange = $file !== null && isset($diffs[$file]);
            $outcome = $inChange && $ran !== null && $name !== null ? TestReport::outcome($ran, $file, $name) : null;
            $key = $file !== null && $name !== null ? TestRefusals::key($file, $name) : null;

            return [
                'criterion' => $item['criterion'],
                'kind' => $item['kind'],
                'case' => $item['text'],
                'test_file' => $file,
                'test_name' => $name,
                'evidence' => match (true) {
                    ! $inChange => 'no_test',
                    ! Capability::runBySuite($file) => 'not_run_by_checks',
                    ! $suitePassed || $outcome === TestReport::FAILED => 'not_run',
                    $ran === null => 'claimed',
                    $outcome !== TestReport::PASSED => 'not_run_by_checks',
                    $this->passesWithoutChange($newTests, (string) $file, (string) $name) => 'passes_without_change',
                    $item['kind'] === 'exception' && $refusals !== null && ! isset($refusals[$key]) => 'no_request',
                    $item['kind'] === 'exception' && $refusals !== null && ! $refusals[$key] => 'not_refused',
                    default => 'tested',
                },
                'named_in_diff' => $inChange && $name !== null && str_contains($diffs[$file], $name),
            ];
        }, $plan->verifyItems(), array_keys($plan->verifyItems()));
    }

    /**
     * Determine if the named test is new and passed with the change taken out.
     *
     * @param  list<array{file: string, name: string, without_change: string}>  $newTests
     */
    protected function passesWithoutChange(array $newTests, string $file, string $name): bool
    {
        return collect($newTests)->contains(fn (array $test) => $test['file'] === $file
            && $test['without_change'] === 'passed'
            && TestRefusals::key($test['file'], $test['name']) === TestRefusals::key($file, $name));
    }
}
