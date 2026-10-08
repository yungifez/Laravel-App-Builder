<?php

namespace App\Actions\Context;

use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Enums\PreservationEvidence;
use App\Features\TestReport;
use App\Features\TestResults;
use App\Runs\Plan;
use App\Runs\Review;

class AssessPreservation
{
    /**
     * Say what is known about each thing the brief said to keep as it is,
     * claiming no more than the evidence shows. In order of precedence:
     *
     * - regression suspected: a blocking review finding names a file in the
     *   area, or the change reached the area where the brief did not expect;
     * - tests failed: a test of the area failed;
     * - related tests passed: the suite passed and its report shows the
     *   area's own tests ran and passed, which does not show the specific
     *   behaviour was exercised;
     * - not edited: no file the area claims changed;
     * - not checked.
     *
     * A test the change itself rewrote is never counted: it proves what the
     * code does now, not that what was there before still holds. The review
     * and the evidence never disagree silently: when the reviewer does not
     * approve the change, every item that is not already a suspected
     * regression is marked as objected to.
     *
     * @param  list<array{name: string, stage: string, outcome: string, output?: string, tests?: list<array{file: string, name: string, outcome: string}>}>  $verificationResults
     * @return list<array{area: string|null, statement: string, evidence: string, unchanged: bool, tests: int, review_objected: bool}>
     */
    public function handle(Plan $plan, ChangeClassification $classification, ProjectContext $context, array $verificationResults, ?Review $review = null): array
    {
        $suiteName = (string) config('builder.verification.suite_check');
        $suite = collect($verificationResults)->first(fn (array $result) => $result['name'] === $suiteName);
        $suitePassed = ($suite['outcome'] ?? null) === 'passed';
        // Without a report of each test, nothing shows which tests ran.
        $ran = $suite['tests'] ?? [];
        $failing = $this->failingFiles($suite, $ran);
        $touched = $classification->touched();
        $changed = $classification->changedFiles();
        $suspected = [...array_keys($classification->unexpected), ...$this->blockingAreas($review, $context)];

        return array_map(function (array $item) use ($context, $suitePassed, $ran, $failing, $touched, $changed, $suspected, $review) {
            $capability = $item['area'] !== null ? ($context->capabilities[$item['area']] ?? null) : null;
            $unchanged = $capability !== null && ! in_array($capability->key, $touched, true);
            $tests = $capability === null ? 0 : count(array_filter(
                array_diff($capability->testFiles, $changed),
                fn (string $file) => TestReport::filePassed($ran, $file),
            ));

            $evidence = match (true) {
                $capability !== null && in_array($capability->key, $suspected, true) => PreservationEvidence::RegressionSuspected,
                $capability !== null && array_intersect($capability->testFiles, $failing) !== [] => PreservationEvidence::TestsFailed,
                $tests > 0 && $suitePassed => PreservationEvidence::RelatedTestsPassed,
                $unchanged => PreservationEvidence::NotEdited,
                default => PreservationEvidence::NotChecked,
            };

            return [
                'area' => $capability?->key,
                'statement' => $item['statement'],
                'evidence' => $evidence->value,
                'unchanged' => $unchanged,
                'tests' => $tests,
                'review_objected' => $review !== null && ! $review->approved && $evidence !== PreservationEvidence::RegressionSuspected,
            ];
        }, $plan->preserve);
    }

    /**
     * Get the test files with a failing test: from the suite's report, or
     * from its output when it wrote none.
     *
     * @param  array{outcome: string, output?: string}|null  $suite
     * @param  list<array{file: string, name: string, outcome: string}>  $ran
     * @return list<string>
     */
    protected function failingFiles(?array $suite, array $ran): array
    {
        if ($suite === null || $suite['outcome'] === 'passed') {
            return [];
        }

        if ($ran === []) {
            return TestResults::failingFiles((string) ($suite['output'] ?? ''));
        }

        return array_values(collect($ran)
            ->where('outcome', TestReport::FAILED)
            ->map(fn (array $test) => str_replace('\\', '/', $test['file']))
            ->map(fn (string $file) => str_contains($file, '/tests/') ? 'tests/'.explode('/tests/', $file, 2)[1] : $file)
            ->unique()
            ->all());
    }

    /**
     * Get the areas that blocking review findings point to, by the files
     * they name.
     *
     * @return list<string>
     */
    protected function blockingAreas(?Review $review, ProjectContext $context): array
    {
        $areas = [];

        foreach ($review === null ? [] : $review->blockingFindings() as $finding) {
            if ($finding['file'] !== null) {
                $areas = [...$areas, ...$context->claiming($finding['file'])];
            }
        }

        return array_values(array_unique($areas));
    }
}
