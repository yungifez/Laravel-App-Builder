<?php

namespace App\Features;

use App\Context\Capability;
use App\Context\ProjectContext;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * Which of the project's tests ran which of its code files, as observed by
 * running the suite with code coverage (direction 22). It is evidence of what
 * tests exercise, never a complete dependency graph: code no test runs has
 * no entry, and that absence means "unknown", not "unaffected".
 *
 * It is read from PHPUnit's documented reports (the coverage XML and the
 * test list XML), never from a test runner's private cache.
 */
final readonly class TestMap
{
    /**
     * The group prefix that ties a test to the behaviour it proves.
     */
    public const BEHAVIOR_GROUP = 'behavior:';

    /**
     * Lines one test ran that are at most this far apart share a range: the
     * lines between (blank lines, comments, braces) are not executable, so
     * the coverage report leaves them out, but they belong to the same code.
     */
    protected const RANGE_GAP = 3;

    /**
     * @param  list<array{id: string, file: string|null, groups: list<string>}>  $tests
     * @param  array<string, list<int>>  $files  Each code file, relative to the project, with the tests (indexes into $tests) that ran it
     * @param  array<string, array<int, list<array{int, int}>>>  $lines  Each code file's line ranges (first and last line) per test that ran them; empty for maps kept before lines were
     */
    public function __construct(
        public array $tests = [],
        public array $files = [],
        public array $lines = [],
    ) {}

    /**
     * Read the map from the condensed coverage report and the test list.
     *
     * The coverage report is a list of lines: the directory the suite ran in,
     * the coverage report's `<project source="…">`, then, for each covered
     * file, its `<file name="…" path="…">` followed, per line, by its
     * `<line nr="…">` and one `covered by="…"` per test that ran it.
     */
    public static function parse(string $coverage, ?string $listing = null): self
    {
        $lines = preg_split('/\R/', trim($coverage)) ?: [];
        $root = rtrim(trim((string) array_shift($lines)), '/');
        $source = preg_match('/source="([^"]*)"/', (string) array_shift($lines), $match) === 1 ? rtrim(self::decode($match[1]), '/') : $root;

        [$tests, $index] = self::listed($listing, $root);
        $files = [];
        $ranLines = [];
        $current = null;
        $number = null;

        foreach ($lines as $line) {
            if (preg_match('/<file name="([^"]*)" path="([^"]*)"/', $line, $file) === 1) {
                $absolute = $source.'/'.trim(self::decode($file[2]), '/').'/'.self::decode($file[1]);
                $current = self::relative(str_replace('//', '/', $absolute), $root);
                $number = null;

                continue;
            }

            if (preg_match('/<line nr="(\d+)"/', $line, $nr) === 1) {
                $number = (int) $nr[1];

                continue;
            }

            if ($current === null || preg_match('/covered by="([^"]*)"/', $line, $covered) !== 1) {
                continue;
            }

            $id = self::testId(self::decode($covered[1]));

            if (! isset($index[$id])) {
                $index[$id] = count($tests);
                $tests[] = ['id' => $id, 'file' => self::guessFile($id), 'groups' => []];
            }

            $files[$current][$index[$id]] = true;

            if ($number !== null) {
                $ranLines[$current][$index[$id]][$number] = true;
            }
        }

        return new self(
            $tests,
            array_map(fn (array $set) => array_keys($set), $files),
            array_map(fn (array $perTest) => array_map(fn (array $numbers) => self::ranges(array_keys($numbers)), $perTest), $ranLines),
        );
    }

    /**
     * Restore a map from storage.
     *
     * @param  list<array{id: string, file: string|null, groups: list<string>}>  $tests
     * @param  array<string, list<int>>  $files
     * @param  array<string, array<int, list<array{int, int}>>>  $lines
     */
    public static function fromArray(array $tests, array $files, array $lines = []): self
    {
        return new self($tests, $files, $lines);
    }

    /**
     * Determine if the map observed nothing, as when no coverage driver ran.
     */
    public function isEmpty(): bool
    {
        return $this->files === [];
    }

    /**
     * Get the foundation: the code files more than the configured share of
     * the tests ran. They tie every area to every other, so they say nothing
     * about any one area; a change to them is broad. A small suite has none.
     *
     * @return list<string>
     */
    public function foundation(): array
    {
        return array_keys(array_filter($this->files, fn (array $ran) => $this->reachesMost(count($ran))));
    }

    /**
     * Determine if this many tests are more than the configured share of a
     * suite big enough to tell.
     */
    public function reachesMost(int $tests): bool
    {
        $suite = count($this->tests);

        return $suite >= (int) config('builder.verification.test_map.foundation_min_tests')
            && $tests > (float) config('builder.verification.test_map.foundation_share') * $suite;
    }

    /**
     * Get the tests that ran any of the given files.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public function testsRunning(array $paths): array
    {
        $tests = [];

        foreach ($paths as $path) {
            foreach ($this->files[$path] ?? [] as $test) {
                $tests[$test] = true;
            }
        }

        return array_keys($tests);
    }

    /**
     * Get the tests that ran an area's own code: the code files it claims,
     * leaving out tests and the foundation, which say nothing about it.
     *
     * @return list<int>
     */
    public function testsForArea(Capability $capability): array
    {
        $foundation = $this->foundation();

        return $this->testsRunning(array_values(array_filter(
            array_keys($this->files),
            fn (string $path) => ! Capability::runBySuite($path) && ! in_array($path, $foundation, true) && $capability->claims($path),
        )));
    }

    /**
     * Say what a test checks, in the words its author gave it: the method
     * name without its prefix ("test_owners_rename_teams" becomes "Owners
     * rename teams"), or a Pest description, without its data set.
     */
    public function sentence(int $test): string
    {
        return self::describe(Str::afterLast($this->tests[$test]['id'] ?? '', '::'));
    }

    /**
     * Say what a test checks from its name alone: a method name or a Pest
     * description.
     */
    public static function describe(string $name): string
    {
        $name = (string) preg_replace('/ with data set .*$/', '', $name);
        $name = (string) preg_replace('/^(__pest_evaluable_|test_?)/', '', $name);
        // camelCase words part at each capital; digits stay with their words.
        $name = Str::squish((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', str_replace('_', ' ', $name)));

        return Str::ucfirst(Str::lower($name));
    }

    /**
     * Determine if the map could have seen a file: code coverage measured
     * other files in its top folder. Coverage usually measures app/ alone,
     * so a config file, a migration or bootstrap/app.php no test "ran" is
     * unknown to the map, not code without tests.
     */
    public function measures(string $path): bool
    {
        $folder = Str::before($path, '/').'/';

        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $folder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the tests that ran any of the given lines of a file, or null when
     * the map cannot say: it kept no lines for the file, or no test ran any
     * of them (a new method, a signature). The caller then goes by the whole
     * file, which reaches more, never less.
     *
     * @param  list<int>  $numbers
     * @return list<int>|null
     */
    public function testsRunningLines(string $path, array $numbers): ?array
    {
        $tests = [];

        foreach ($this->lines[$path] ?? [] as $test => $ranges) {
            foreach ($ranges as [$first, $last]) {
                foreach ($numbers as $number) {
                    if ($number >= $first && $number <= $last) {
                        $tests[$test] = true;

                        continue 3;
                    }
                }
            }
        }

        return $tests === [] ? null : array_keys($tests);
    }

    /**
     * Get the areas a test belongs to: the area of each behaviour it proves
     * (its `behavior:<key>` groups), and the areas that claim its file.
     *
     * @return list<string>
     */
    public function areasOf(int $test, ProjectContext $context): array
    {
        $entry = $this->tests[$test] ?? null;

        if ($entry === null) {
            return [];
        }

        $behaviors = array_map(
            fn (string $group) => Str::after($group, self::BEHAVIOR_GROUP),
            array_filter($entry['groups'], fn (string $group) => str_starts_with($group, self::BEHAVIOR_GROUP)),
        );
        $areas = [];

        foreach ($context->capabilities as $capability) {
            $proves = array_intersect($behaviors, array_column($capability->behaviors, 'key')) !== [];

            if ($proves || ($entry['file'] !== null && $capability->claims($entry['file']))) {
                $areas[] = $capability->key;
            }
        }

        return $areas;
    }

    /**
     * Get the behaviour keys that at least one test proves.
     *
     * @return list<string>
     */
    public function provenBehaviors(): array
    {
        $keys = [];

        foreach ($this->tests as $test) {
            foreach ($test['groups'] as $group) {
                if (str_starts_with($group, self::BEHAVIOR_GROUP)) {
                    $keys[Str::after($group, self::BEHAVIOR_GROUP)] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Read the tests, their files and groups from PHPUnit's test list.
     *
     * @return array{0: list<array{id: string, file: string|null, groups: list<string>}>, 1: array<string, int>}
     */
    protected static function listed(?string $listing, string $root): array
    {
        $tests = [];
        $index = [];

        if (blank($listing)) {
            return [$tests, $index];
        }

        // Without its default namespace the list reads with plain paths.
        try {
            $xml = new SimpleXMLElement((string) preg_replace('/\sxmlns="[^"]*"/', '', (string) $listing, 1));
        } catch (Throwable) {
            return [$tests, $index];
        }

        $groups = [];

        foreach ($xml->xpath('//group') ?: [] as $group) {
            foreach ($group->xpath('test') ?: [] as $test) {
                $groups[self::testId((string) $test['id'])][(string) $group['name']] = true;
            }
        }

        foreach ($xml->xpath('//testClass') ?: [] as $class) {
            $file = self::relative((string) $class['file'], $root);

            foreach ($class->xpath('testMethod') ?: [] as $method) {
                $id = self::testId((string) $method['id']);

                if (! isset($index[$id])) {
                    $index[$id] = count($tests);
                    $tests[] = ['id' => $id, 'file' => $file !== '' ? $file : self::guessFile($id), 'groups' => array_keys($groups[$id] ?? [])];
                }
            }
        }

        return [$tests, $index];
    }

    /**
     * Collapse line numbers into ranges, bridging short gaps.
     *
     * @param  list<int>  $numbers
     * @return list<array{int, int}>
     */
    protected static function ranges(array $numbers): array
    {
        sort($numbers);
        $ranges = [];

        foreach ($numbers as $number) {
            $last = array_key_last($ranges);

            if ($last !== null && $number - $ranges[$last][1] <= self::RANGE_GAP) {
                $ranges[$last][1] = $number;
            } else {
                $ranges[] = [$number, $number];
            }
        }

        return $ranges;
    }

    /**
     * Name a test by class and method only, so each data set of one test
     * counts as that test.
     */
    protected static function testId(string $name): string
    {
        return (string) preg_replace('/(\s+with data set .*|#.*)$/s', '', trim($name));
    }

    /**
     * Guess a test's file from its class name, for tests the list left out.
     */
    protected static function guessFile(string $id): ?string
    {
        $class = Str::before($id, '::');
        $class = Str::startsWith($class, 'P\\') ? Str::after($class, 'P\\') : $class;

        if (! Str::startsWith($class, 'Tests\\')) {
            return null;
        }

        return 'tests/'.str_replace('\\', '/', Str::after($class, 'Tests\\')).'.php';
    }

    /**
     * Make an absolute path inside the project relative to it.
     */
    protected static function relative(string $path, string $root): string
    {
        return $root !== '' && str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : ltrim($path, '/');
    }

    /**
     * Decode an XML attribute value.
     */
    protected static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1);
    }
}
