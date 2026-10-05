<?php

namespace App\Features;

use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * The tests a check actually ran, read from its JUnit report, so a claim
 * that a test covers something can be held against what ran.
 */
class TestReport
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /**
     * Read each test case in a JUnit report: its file, name and outcome.
     * An unreadable report reads as no tests.
     *
     * @return list<array{file: string, name: string, outcome: string}>
     */
    public static function fromJunit(string $xml): array
    {
        try {
            $report = new SimpleXMLElement($xml, LIBXML_NONET);
        } catch (Throwable) {
            return [];
        }

        $tests = [];

        foreach ($report->xpath('//testcase') ?: [] as $case) {
            $suiteFile = $case->xpath('ancestor::testsuite[@file][1]/@file');

            $tests[] = [
                'file' => Str::before((string) ($case['file'] ?? ($suiteFile[0] ?? '')), '::'),
                'name' => (string) $case['name'],
                'outcome' => match (true) {
                    isset($case->failure), isset($case->error) => self::FAILED,
                    isset($case->skipped) => self::SKIPPED,
                    default => self::PASSED,
                },
            ];
        }

        return $tests;
    }

    /**
     * Find how a named test in a file ended. PHPUnit method names and Pest
     * descriptions both match ("test_teams_have_a_description", "teams have
     * a description"), and each data set of a test counts; one that did not
     * pass makes the test not pass.
     *
     * @param  list<array{file: string, name: string, outcome: string}>  $tests
     * @return string|null The outcome, or null when no such test ran
     */
    public static function outcome(array $tests, string $file, string $name): ?string
    {
        $wanted = self::normalize($name);
        $outcomes = [];

        foreach ($tests as $test) {
            $path = str_replace('\\', '/', $test['file']);

            if (($path === $file || str_ends_with($path, '/'.$file)) && self::normalize($test['name']) === $wanted) {
                $outcomes[] = $test['outcome'];
            }
        }

        return match (true) {
            $outcomes === [] => null,
            in_array(self::FAILED, $outcomes, true) => self::FAILED,
            in_array(self::PASSED, $outcomes, true) => self::PASSED,
            default => self::SKIPPED,
        };
    }

    /**
     * Whether a test file ran and passed: at least one of its tests passed
     * and none failed.
     *
     * @param  list<array{file: string, name: string, outcome: string}>  $tests
     */
    public static function filePassed(array $tests, string $file): bool
    {
        $outcomes = collect($tests)
            ->filter(function (array $test) use ($file) {
                $path = str_replace('\\', '/', $test['file']);

                return $path === $file || str_ends_with($path, '/'.$file);
            })
            ->pluck('outcome');

        return $outcomes->contains(self::PASSED) && ! $outcomes->contains(self::FAILED);
    }

    /**
     * Reduce a test name to its words: no "test" or "it" prefix, no data
     * set, underscores as spaces.
     */
    protected static function normalize(string $name): string
    {
        $name = Str::of($name)->before(' with data set ')->before(' with (')->replace('_', ' ')->lower()->squish()->toString();

        return (string) preg_replace('/^(test|it)\s+/', '', $name);
    }
}
