<?php

namespace App\Features;

use App\Context\Capability;

/**
 * How far tests reach into the code a change adds, line by line, from the
 * coverage the suite recorded (direction 22).
 *
 * Three kinds of new line that can run are told apart. A line a test the
 * app already had runs is checked by something the change did not write.
 * A line only the change's own tests run is checked by its author alone.
 * A line no test runs is not checked at all. Lines that cannot run
 * (comments, braces, signatures) are not counted.
 */
class NewCode
{
    /**
     * The most unrun lines kept per file, and files kept, so one change
     * cannot fill the row.
     */
    protected const KEPT_LINES = 60;

    protected const KEPT_FILES = 40;

    /**
     * Determine if so much of the new code was run by no test that it is a
     * gap in what checks the change. One line no test ran is common and
     * harmless; a share of them above the configured one is not.
     *
     * @param  array{lines: int, run: int, own_tests_only?: int, unrun?: array<string, list<int>>}  $measured  From measure()
     */
    public static function gap(array $measured): bool
    {
        $unrun = $measured['lines'] - $measured['run'];

        return $unrun > 0 && $unrun / $measured['lines'] > (float) config('builder.verification.change_evidence.unrun_gap_share');
    }

    /**
     * Read the condensed line report: the directory the suite ran in, then,
     * for each file, its `<file name="…"` followed by one
     * `<line num="…" type="stmt" count="…"` per line that can run, with how
     * many times the suite ran it.
     *
     * @return array<string, array<int, int>> Each code file, relative to the project, with its lines that can run and their counts
     */
    public static function parse(string $report): array
    {
        $rows = preg_split('/\R/', trim($report)) ?: [];
        $root = rtrim(trim((string) array_shift($rows)), '/');
        $files = [];
        $current = null;

        foreach ($rows as $row) {
            if (preg_match('/<file name="([^"]*)"/', $row, $file) === 1) {
                $path = html_entity_decode($file[1], ENT_QUOTES | ENT_XML1);
                $current = $root !== '' && str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : ltrim($path, '/');

                continue;
            }

            if ($current !== null && preg_match('/<line num="(\d+)" type="stmt" count="(\d+)"/', $row, $line) === 1) {
                $files[$current][(int) $line[1]] = (int) $line[2];
            }
        }

        return $files;
    }

    /**
     * Measure the lines the patch adds to the app's PHP code: how many can
     * run, how many a test ran, how many only the change's own tests ran,
     * and which no test ran. Null when the report measured none of them.
     *
     * @param  array<string, array<int, int>>  $lines  From parse()
     * @param  list<string>  $ownTests  The test files the change added or changed
     * @return array{lines: int, run: int, own_tests_only: int, unrun: array<string, list<int>>}|null
     */
    public static function measure(TestMap $map, array $lines, ?string $patch, array $ownTests): ?array
    {
        $total = 0;
        $run = 0;
        $ownOnly = 0;
        $unrun = [];

        foreach (PatchSummary::files($patch) as $file) {
            $path = $file['path'];

            if (! str_ends_with($path, '.php') || Capability::runBySuite($path) || ! isset($lines[$path])) {
                continue;
            }

            foreach (array_column(PatchSummary::addedLines($file['diff']), 'line') as $number) {
                if (! isset($lines[$path][$number])) {
                    continue;
                }

                $total++;

                if ($lines[$path][$number] === 0) {
                    $unrun[$path][] = $number;

                    continue;
                }

                $run++;
                $tests = $map->testsRunningLines($path, [$number]) ?? [];

                if ($tests !== [] && array_all($tests, fn (int $test) => in_array($map->tests[$test]['file'] ?? null, $ownTests, true))) {
                    $ownOnly++;
                }
            }
        }

        if ($total === 0) {
            return null;
        }

        return [
            'lines' => $total,
            'run' => $run,
            'own_tests_only' => $ownOnly,
            'unrun' => array_slice(array_map(fn (array $numbers) => array_slice($numbers, 0, self::KEPT_LINES), $unrun), 0, self::KEPT_FILES, preserve_keys: true),
        ];
    }
}
