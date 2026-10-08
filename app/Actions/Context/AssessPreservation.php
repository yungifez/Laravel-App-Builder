<?php

namespace App\Actions\Context;

use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Enums\PreservationEvidence;
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
     * - tests failed: a failing test belongs to the area;
     * - related tests passed: the area has tests and the suite passed, which
     *   does not show the specific behaviour was exercised;
     * - not edited: no file the area claims changed;
     * - not checked.
     *
     * The review and the evidence never disagree silently: when the reviewer
     * does not approve the change, every item that is not already a
     * suspected regression is marked as objected to.
     *
     * @param  list<array{name: string, stage: string, outcome: string, output?: string}>  $verificationResults
     * @return list<array{area: string|null, statement: string, evidence: string, unchanged: bool, tests: int, review_objected: bool}>
     */
    public function handle(Plan $plan, ChangeClassification $classification, ProjectContext $context, array $verificationResults, ?Review $review = null): array
    {
        $suite = (string) config('builder.verification.suite_check');
        $suiteResult = collect($verificationResults)->firstWhere('name', $suite);
        $suitePassed = ($suiteResult['outcome'] ?? null) === 'passed';
        $failingFiles = $suiteResult !== null && ! $suitePassed ? TestResults::failingFiles((string) ($suiteResult['output'] ?? '')) : [];
        $touched = $classification->touched();
        $suspected = [...array_keys($classification->unexpected), ...$this->blockingAreas($review, $context)];

        return array_map(function (array $item) use ($context, $suitePassed, $failingFiles, $touched, $suspected, $review) {
            $capability = $item['area'] !== null ? ($context->capabilities[$item['area']] ?? null) : null;
            $unchanged = $capability !== null && ! in_array($capability->key, $touched, true);
            $tests = $capability === null ? 0 : count($capability->testFiles);

            $evidence = match (true) {
                $capability !== null && in_array($capability->key, $suspected, true) => PreservationEvidence::RegressionSuspected,
                $capability !== null && array_intersect($capability->testFiles, $failingFiles) !== [] => PreservationEvidence::TestsFailed,
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
