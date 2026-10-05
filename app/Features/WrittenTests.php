<?php

namespace App\Features;

use App\Context\Capability;
use App\Runs\Exceptions\ConstructionFailed;
use Illuminate\Support\Str;
use ParseError;

/**
 * The tests written from the plan before the change is built (§12), held to
 * fixed rules before any of them is used: each file is new, parses as PHP and
 * is run by the suite, and each item the tests must check has exactly one
 * test of its own that the file really holds. Nothing here asks a model.
 */
class WrittenTests
{
    /**
     * Check the writer's output against the plan's numbered items.
     *
     * @param  array<string, mixed>  $output  The writer's structured output
     * @param  int  $items  How many items the tests must check, numbered from 1
     * @param  callable(string): bool  $exists  Whether a file already exists in the app
     * @return array{files: array<string, string>, tests: list<array{item: int, file: string, name: string}>}
     *
     * @throws ConstructionFailed with every rule the output broke
     */
    public static function check(array $output, int $items, callable $exists): array
    {
        $problems = [];
        $files = [];
        $bytes = 0;

        foreach (is_array($output['files'] ?? null) ? $output['files'] : [] as $file) {
            $path = self::path(is_array($file) && is_string($file['path'] ?? null) ? $file['path'] : '');
            $contents = is_array($file) && is_string($file['contents'] ?? null) ? $file['contents'] : '';
            $bytes += strlen($contents);

            $problem = match (true) {
                $path === '' || str_contains($path, '..') => __('A file has no usable path.'),
                ! Capability::runBySuite($path) || ! str_starts_with($path, 'tests/') || str_starts_with($path, AcceptanceSuite::WORKSPACE_DIRECTORY.'/') => __('The file :path is not where the test suite runs it: put it under tests/Feature in a file whose name ends in Test.php.', ['path' => $path]),
                isset($files[$path]) => __('The file :path is given twice.', ['path' => $path]),
                $exists($path) => __('The file :path already exists. Write new files only.', ['path' => $path]),
                ! self::parses($contents) => __('The file :path is not valid PHP.', ['path' => $path]),
                default => null,
            };

            if ($problem !== null) {
                $problems[] = $problem;
            } else {
                $files[$path] = $contents;
            }
        }

        if ($files === [] && $problems === []) {
            $problems[] = __('No test files were written.');
        }

        if (count($files) > (int) config('builder.verification.written_first.max_files')) {
            $problems[] = __('Write at most :max files.', ['max' => (int) config('builder.verification.written_first.max_files')]);
        }

        if ($bytes > (int) config('builder.verification.written_first.max_bytes')) {
            $problems[] = __('The files are too long. Keep each test to what its item needs.');
        }

        $tests = [];
        $named = [];

        foreach (is_array($output['tests'] ?? null) ? $output['tests'] : [] as $test) {
            $item = is_array($test) && is_int($test['item'] ?? null) ? $test['item'] : 0;
            $path = self::path(is_array($test) && is_string($test['file'] ?? null) ? $test['file'] : '');
            $name = is_array($test) && is_string($test['name'] ?? null) ? trim($test['name']) : '';
            $key = self::name($name);

            $problem = match (true) {
                $item < 1 || $item > $items => __('There is no item :item.', ['item' => $item]),
                isset($tests[$item]) => __('Item :item has more than one test.', ['item' => $item]),
                ! isset($files[$path]) => __('The test for item :item is in :path, which is not one of the files written.', ['item' => $item, 'path' => $path]),
                $key === '' || ! in_array($key, self::names($files[$path]), true) => __('The file :path has no test named ":name" for item :item.', ['path' => $path, 'name' => $name, 'item' => $item]),
                isset($named["{$path}|{$key}"]) => __('The test ":name" is given for more than one item: a test checks one item.', ['name' => $name]),
                default => null,
            };

            if ($problem !== null) {
                $problems[] = $problem;

                continue;
            }

            $named["{$path}|{$key}"] = true;
            $tests[$item] = ['item' => $item, 'file' => $path, 'name' => $name];
        }

        foreach (range(1, max($items, 1)) as $item) {
            if ($items > 0 && ! isset($tests[$item])) {
                $problems[] = __('Item :item has no test.', ['item' => $item]);
            }
        }

        if ($problems !== []) {
            throw new ConstructionFailed(implode("\n", array_unique($problems)));
        }

        ksort($tests);

        return ['files' => $files, 'tests' => array_values($tests)];
    }

    /**
     * Get the names of the tests a file holds, normalised: Pest's test()
     * and it() descriptions, and PHPUnit's test methods.
     *
     * @return list<string>
     */
    public static function names(string $contents): array
    {
        preg_match_all('/\b(?:test|it)\(\s*([\'"])(.+?)(?<!\\\\)\1/s', $contents, $pest);
        preg_match_all('/function\s+(test\w*)\s*\(/i', $contents, $methods);
        preg_match_all('/#\[Test\]\s*(?:public\s+)?function\s+(\w+)\s*\(/', $contents, $attributed);

        return array_values(array_unique(array_filter(array_map(self::name(...), [...$pest[2], ...$methods[1], ...$attributed[1]]))));
    }

    /**
     * Name a test the way the test report and the trace name it.
     */
    protected static function name(string $name): string
    {
        return Str::after(TestRefusals::key('', $name), '|');
    }

    protected static function path(string $path): string
    {
        return Str::chopStart(ltrim(trim($path), '/'), './');
    }

    protected static function parses(string $contents): bool
    {
        if (! str_starts_with(ltrim($contents), '<?php')) {
            return false;
        }

        try {
            return token_get_all($contents, TOKEN_PARSE) !== [];
        } catch (ParseError) {
            return false;
        }
    }
}
