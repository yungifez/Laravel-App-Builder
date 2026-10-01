<?php

namespace App\Features;

use App\Context\Capability;
use Illuminate\Support\Str;

/**
 * The tests a change adds, and whether each one fails without the change.
 *
 * A coder can misread a request, build the misreading and write tests that
 * match it. Nothing that runs can catch the misreading, but one thing can
 * be measured: a new test that passes on the app as it was says nothing
 * about the change. So the new tests are run once more with the change's
 * code taken out and only its tests left in. One that fails there tried
 * what the change does; one that passes there did not.
 */
class NewTests
{
    public const PASSED = TestReport::PASSED;

    public const FAILED = TestReport::FAILED;

    /**
     * Get the test files the changes leave in the app that the suite runs,
     * each with whether the app already had it. The changes are given
     * oldest first; a file a later one deletes is left out.
     *
     * @param  list<string|null>  $patches
     * @return array<string, bool>
     */
    public static function files(array $patches): array
    {
        $files = [];

        foreach ($patches as $patch) {
            foreach (PatchSummary::files($patch) as $file) {
                $path = $file['path'];

                if (! Capability::runBySuite($path)) {
                    continue;
                }

                if (str_contains($file['diff'], "\ndeleted file mode ")) {
                    unset($files[$path]);

                    continue;
                }

                $files[$path] ??= ! str_contains($file['diff'], "\nnew file mode ") && ! str_contains($file['diff'], "\n--- /dev/null");
            }
        }

        return $files;
    }

    /**
     * Name each test the change added and how it ended without the change.
     *
     * $before lists the tests the files held on the starting commit, and
     * $without how every test in them ended with only the change's tests
     * put back. A file the app already had whose old tests are unknown is
     * left out: its old tests cannot be told from its new ones. A skipped
     * test says nothing, so it is left out too.
     *
     * @param  array<string, bool>  $files  From files()
     * @param  list<array{file: string, name: string, outcome: string}>  $before
     * @param  list<array{file: string, name: string, outcome: string}>  $without
     * @return list<array{file: string, name: string, without_change: string}>
     */
    public static function found(array $files, array $before, array $without): array
    {
        $found = [];

        foreach ($files as $file => $existed) {
            $old = self::in($before, $file);

            if ($existed && $old === []) {
                continue;
            }

            $seen = [];

            foreach (self::in($without, $file) as $test) {
                $name = Str::of($test['name'])->before(' with data set ')->before(' with (')->toString();

                if (isset($seen[$name]) || TestReport::outcome($old, $file, $name) !== null) {
                    continue;
                }

                $seen[$name] = true;
                $outcome = TestReport::outcome($without, $file, $name);

                if (in_array($outcome, [self::PASSED, self::FAILED], true)) {
                    $found[] = ['file' => $file, 'name' => $name, 'without_change' => $outcome];
                }
            }
        }

        return $found;
    }

    /**
     * Get the new tests in the patch's files that ended the given way
     * without the change, each in its author's words.
     *
     * @param  list<array{file: string, name: string, without_change: string}>  $tests
     * @return list<string>
     */
    public static function ending(array $tests, string $outcome, ?string $patch): array
    {
        $files = array_column(PatchSummary::files($patch), 'path');

        return array_values(array_map(
            fn (array $test) => TestMap::describe($test['name']),
            array_filter($tests, fn (array $test) => $test['without_change'] === $outcome && in_array($test['file'], $files, true)),
        ));
    }

    /**
     * Get the reported tests of one file, whose path a report gives in full.
     *
     * @param  list<array{file: string, name: string, outcome: string}>  $tests
     * @return list<array{file: string, name: string, outcome: string}>
     */
    protected static function in(array $tests, string $file): array
    {
        return array_values(array_filter($tests, function (array $test) use ($file) {
            $path = str_replace('\\', '/', $test['file']);

            return $path === $file || str_ends_with($path, '/'.$file);
        }));
    }
}
