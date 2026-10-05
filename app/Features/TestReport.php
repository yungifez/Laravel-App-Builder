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
     * Read each test case in a JUnit report: its file, name and outcome,
     * and for a failed one, what it said. An unreadable report reads as no
     * tests.
     *
     * @return list<array{file: string, name: string, outcome: string, message?: string}>
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

            $failure = match (true) {
                isset($case->failure) => (string) $case->failure,
                isset($case->error) => (string) $case->error,
                default => null,
            };

            $tests[] = [
                'file' => Str::before((string) ($case['file'] ?? ($suiteFile[0] ?? '')), '::'),
                'name' => (string) $case['name'],
                'outcome' => match (true) {
                    $failure !== null => self::FAILED,
                    isset($case->skipped) => self::SKIPPED,
                    default => self::PASSED,
                },
                ...($failure === null ? [] : ['message' => self::message($failure)]),
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
        $outcomes = [];

        foreach ($tests as $test) {
            if (self::same($test, $file, $name)) {
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
     * Determine if a test in a report is the named test in the file.
     *
     * @param  array{file: string, name: string}  $test
     */
    public static function same(array $test, string $file, string $name): bool
    {
        $path = str_replace('\\', '/', $test['file']);

        return ($path === $file || str_ends_with($path, '/'.$file)) && self::normalize($test['name']) === self::normalize($name);
    }

    /**
     * Keep what a failed test said, without the test's own name on the
     * first line or where it stopped below: the same failure on two tries
     * then reads the same.
     */
    protected static function message(string $text): string
    {
        $lines = explode("\n", trim(str_replace("\r\n", "\n", $text)));

        if (preg_match('/^[\w\\\\]+::\S+/', $lines[0]) === 1) {
            array_shift($lines);
        }

        return Str::limit(trim(Str::before(trim(implode("\n", $lines)), "\n\n")), 500);
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
