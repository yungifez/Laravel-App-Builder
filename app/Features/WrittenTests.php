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
 * test of its own that the file really holds. An exception item's test must
 * assert a refusal: the checks later require the app to refuse it, and the
 * coder may not change a written test, so a test that expects success could
 * never be met. Nothing here asks a model.
 */
class WrittenTests
{
    /**
     * Check the writer's output against the plan's numbered items.
     *
     * @param  array<string, mixed>  $output  The writer's structured output
     * @param  list<string>  $kinds  The case of each item the tests must check (base, alternate or exception), item 1 first
     * @param  callable(string): bool  $exists  Whether a file already exists in the app
     * @return array{files: array<string, string>, tests: list<array{item: int, file: string, name: string}>}
     *
     * @throws ConstructionFailed with every rule the output broke
     */
    public static function check(array $output, array $kinds, callable $exists): array
    {
        $items = count($kinds);
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
        $refused = [];

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
                $kinds[$item - 1] === 'exception' && ! self::assertsRefusal(self::body($files[$path], $key)) => __('The test ":name" for item :item is an exception case, but it asserts no refusal. Assert that the app refuses: a 403 or 404, validation errors, a redirect to sign in or to confirm an email or password, a thrown exception or failed command, or a record still there after it was deleted.', ['name' => $name, 'item' => $item]),
                default => null,
            };

            if ($problem !== null) {
                $problems[] = $problem;
                // Its reason is given, so it is not also said to have no test.
                $refused[$item] = true;

                continue;
            }

            $named["{$path}|{$key}"] = true;
            $tests[$item] = ['item' => $item, 'file' => $path, 'name' => $name];
        }

        foreach (range(1, max($items, 1)) as $item) {
            if ($items > 0 && ! isset($tests[$item]) && ! isset($refused[$item])) {
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
     * Get the part of a file that holds one test: from where it is declared
     * to where the next test or method is.
     */
    public static function body(string $contents, string $key): string
    {
        preg_match_all('/\b(?:test|it)\(\s*([\'"])(.+?)(?<!\\\\)\1/s', $contents, $pest, PREG_OFFSET_CAPTURE);
        preg_match_all('/function\s+(\w+)\s*\(/i', $contents, $methods, PREG_OFFSET_CAPTURE);
        $starts = [];

        foreach ([[$pest[0], $pest[2]], [$methods[0], $methods[1]]] as [$whole, $names]) {
            foreach ($whole as $index => [, $offset]) {
                $starts[$offset] = self::name($names[$index][0]);
            }
        }

        ksort($starts);
        $offsets = array_keys($starts);

        foreach ($offsets as $index => $offset) {
            if ($starts[$offset] === $key) {
                return substr($contents, $offset, ($offsets[$index + 1] ?? strlen($contents)) - $offset);
            }
        }

        return '';
    }

    /**
     * Determine if a test asserts that the app refused: a 4xx answer,
     * validation errors, a guest sent to sign in, a thrown exception, a
     * failed command, or a record still there after it was deleted, as
     * PHPUnit or Pest write it.
     */
    public static function assertsRefusal(string $test): bool
    {
        return preg_match('/->\s*assert(?:Forbidden|NotFound|Unauthorized|Unprocessable|BadRequest|Conflict|Gone|MethodNotAllowed|PaymentRequired|TooManyRequests|ClientError|Invalid|SessionHasErrors\w*|JsonValidationError\w*|Failed)\s*\(/', $test) === 1
            || preg_match('/assertStatus\(\s*4\d\d\s*\)|->\s*toBe\(\s*4\d\d\s*\)|assert(?:Same|Equals)\(\s*4\d\d\s*,/', $test) === 1
            // The starter kits send a guest to sign in, an unconfirmed
            // email to confirm it, and a stale password to enter it again.
            || preg_match('/assertRedirect(?:ToRoute)?\([^;]*(?:login|verification\.notice|verify-email|password\.confirm|confirm-password)/i', $test) === 1
            || preg_match('/expectException\w*\(|->\s*toThrow\(|->\s*throws\(|assertThrows\(|assertExitCode\(\s*[1-9]/', $test) === 1
            // A record in use that cannot be removed: the test tries to
            // delete it, and it is still there.
            || (preg_match('/->\s*delete(?:Json)?\s*\(/', $test) === 1 && preg_match('/assert(?:ModelExists|DatabaseHas|NotSoftDeleted)\s*\(/', $test) === 1);
    }

    /**
     * Name a test the way the test report and the trace name it.
     */
    public static function name(string $name): string
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
