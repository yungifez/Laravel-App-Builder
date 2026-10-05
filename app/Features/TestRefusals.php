<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * Whether each of a change's tests saw the app refuse (direction 33, §12).
 * An exception case says the app must turn something away: bad input, the
 * wrong person or a record that is not there. Its test passing proves only
 * what the test asserts; the recorded requests prove what the app did. So
 * the evidence for an exception case is a request the app refused while
 * that test ran, read from the trace, never from the test's own words.
 *
 * A refusal is an answer of 400 or more, a request sent back with errors,
 * a command that failed, or a redirect that saved and sent nothing (where
 * Laravel sends a guest to sign in).
 */
class TestRefusals
{
    /**
     * Read, for each test in the test files the patch touched that made a
     * request, whether one of its requests was refused.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string}>}>  $requests  From AppTraces::parse()
     * @return array<string, bool>|null Keyed by key(); null when nothing was recorded
     */
    public static function measure(array $requests, ?string $patch): ?array
    {
        if ($requests === []) {
            return null;
        }

        $classes = [];

        foreach (PatchSummary::files((string) $patch) as $file) {
            if (Str::endsWith($file['path'], '.php') && $file['additions'] > 0) {
                $classes[Str::lower(basename($file['path'], '.php'))] = true;
            }
        }

        $seen = [];

        foreach ($requests as $request) {
            if (! is_string($request['test']) || ! str_contains($request['test'], '::')) {
                continue;
            }

            [$class, $name] = explode('::', $request['test'], 2);
            $key = self::key($class, $name);

            if (! isset($classes[Str::before($key, '|')])) {
                continue;
            }

            $seen[$key] = ($seen[$key] ?? false) || self::refused($request);
        }

        return $seen;
    }

    /**
     * Name a test the same way from its file and its name in the test
     * report, and from its class and method in the trace, and from the
     * reviewer's words for it. PHPUnit names the method; Pest names it
     * after the description, which its report shows in words.
     */
    public static function key(string $fileOrClass, string $name): string
    {
        $base = Str::of($fileOrClass)->afterLast('\\')->afterLast('/')->chopEnd('.php')->lower();
        // As TestReport matches names: "test_" and "it " lead no name.
        $name = Str::of($name)->before(' with data set ')->before(' with (')->chopStart('__pest_evaluable_')->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->replaceMatches('/^(test|it)\s+/', '')->replace(' ', '_');

        return "{$base}|{$name}";
    }

    /**
     * Determine if the app turned a recorded request away.
     *
     * @param  array{status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string}>}  $request
     */
    protected static function refused(array $request): bool
    {
        if ($request['refused'] || $request['status'] >= 400) {
            return true;
        }

        if ($request['status'] < 300) {
            return false;
        }

        // Saving inside a transaction that was rolled back saved nothing.
        // Anything that is not a query or a transaction sent something.
        $sent = array_filter($request['effects'], fn (array $effect) => ! in_array($effect['kind'], ['query', 'begin', 'commit', 'rollback'], true));

        return $sent === [] && AppTraces::saved($request['effects']) === [];
    }
}
