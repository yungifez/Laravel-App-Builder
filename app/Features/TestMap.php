<?php

namespace App\Features;

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
     * @param  list<array{id: string, file: string|null, groups: list<string>}>  $tests
     * @param  array<string, list<int>>  $files  Each code file, relative to the project, with the tests (indexes into $tests) that ran it
     */
    public function __construct(
        public array $tests = [],
        public array $files = [],
    ) {}

    /**
     * Read the map from the condensed coverage report and the test list.
     *
     * The coverage report is a list of lines: the directory the suite ran in,
     * the coverage report's `<project source="…">`, then, for each covered
     * file, its `<file name="…" path="…">` followed by one `covered by="…"`
     * per line a test ran.
     */
    public static function parse(string $coverage, ?string $listing = null): self
    {
        $lines = preg_split('/\R/', trim($coverage)) ?: [];
        $root = rtrim(trim((string) array_shift($lines)), '/');
        $source = preg_match('/source="([^"]*)"/', (string) array_shift($lines), $match) === 1 ? rtrim(self::decode($match[1]), '/') : $root;

        [$tests, $index] = self::listed($listing, $root);
        $files = [];
        $current = null;

        foreach ($lines as $line) {
            if (preg_match('/<file name="([^"]*)" path="([^"]*)"/', $line, $file) === 1) {
                $absolute = $source.'/'.trim(self::decode($file[2]), '/').'/'.self::decode($file[1]);
                $current = self::relative(str_replace('//', '/', $absolute), $root);

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
        }

        return new self($tests, array_map(fn (array $set) => array_keys($set), $files));
    }

    /**
     * Restore a map from storage.
     *
     * @param  list<array{id: string, file: string|null, groups: list<string>}>  $tests
     * @param  array<string, list<int>>  $files
     */
    public static function fromArray(array $tests, array $files): self
    {
        return new self($tests, $files);
    }

    /**
     * Determine if the map observed nothing, as when no coverage driver ran.
     */
    public function isEmpty(): bool
    {
        return $this->files === [];
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
