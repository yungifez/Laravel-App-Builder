<?php

namespace App\Actions\Context;

use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Features\TestReport;
use App\Runs\Plan;
use Illuminate\Support\Str;

class AssessCoverage
{
    /**
     * Say, for each part of the app the change touched, how well its tests
     * cover it: how many of the part's own tests the change left alone and
     * the report shows passed, and whether a test of the change proved each
     * kind of case there. A case counts for a part when the test that
     * proved it lives in that part's tests or in the files the change made
     * there. A kind no criterion of the plan needs is "not_needed".
     *
     * @param  list<array{criterion: string, kind: string, case: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}>  $verified
     * @param  list<array{name: string, stage: string, outcome: string, tests?: list<array{file: string, name: string, outcome: string}>}>  $verificationResults
     * @return list<array{area: string, tests_passed: int, cases: array{base: string, alternate: string, exception: string}}>
     */
    public function handle(Plan $plan, ChangeClassification $classification, ProjectContext $context, array $verified, array $verificationResults): array
    {
        $suiteName = (string) config('builder.verification.suite_check');
        $suite = collect($verificationResults)->first(fn (array $result) => $result['name'] === $suiteName);
        $ran = ($suite['outcome'] ?? null) === 'passed' ? ($suite['tests'] ?? []) : [];
        $changed = $classification->changedFiles();
        $needed = array_values(array_unique(array_column(array_filter($plan->cases, fn (array $case) => $case['says'] !== null), 'kind')));
        $files = [...$classification->requested, ...$classification->mayAlsoAffect, ...$classification->unexpected];
        $coverage = [];

        foreach ($files as $key => $areaFiles) {
            $capability = $context->capabilities[$key] ?? null;

            if ($capability === null) {
                continue;
            }

            $own = [...$capability->testFiles, ...$areaFiles];
            $proved = array_values(array_unique(array_column(array_filter(
                $verified,
                fn (array $item) => $item['evidence'] === 'tested' && $item['test_file'] !== null && in_array(self::path($item['test_file']), $own, true),
            ), 'kind')));

            $coverage[] = [
                'area' => $key,
                'tests_passed' => count(array_filter(
                    array_diff($capability->testFiles, $changed),
                    fn (string $file) => TestReport::filePassed($ran, $file),
                )),
                'cases' => [
                    'base' => self::state('base', $needed, $proved),
                    'alternate' => self::state('alternate', $needed, $proved),
                    'exception' => self::state('exception', $needed, $proved),
                ],
            ];
        }

        return $coverage;
    }

    /**
     * @param  list<string>  $needed
     * @param  list<string>  $proved
     */
    protected static function state(string $kind, array $needed, array $proved): string
    {
        return match (true) {
            ! in_array($kind, $needed, true) => 'not_needed',
            in_array($kind, $proved, true) => 'tested',
            default => 'not_tested',
        };
    }

    /**
     * Name a test file as the change's files are named.
     */
    protected static function path(string $file): string
    {
        return Str::chopStart(ltrim($file, '/'), './');
    }
}
