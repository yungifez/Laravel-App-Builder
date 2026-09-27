<?php

namespace App\Features;

use Illuminate\Support\Str;

class PatchSummary
{
    /**
     * Split a unified git diff into per-file entries with line counts.
     *
     * @return list<array{path: string, additions: int, deletions: int, diff: string}>
     */
    public static function files(?string $patch): array
    {
        if (blank($patch)) {
            return [];
        }

        $files = [];

        foreach (preg_split('/^(?=diff --git )/m', $patch, flags: PREG_SPLIT_NO_EMPTY) ?: [] as $block) {
            if (! str_starts_with($block, 'diff --git ')) {
                continue;
            }

            $lines = explode("\n", $block);
            $additions = 0;
            $deletions = 0;

            foreach ($lines as $line) {
                if (str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                    $additions++;
                } elseif (str_starts_with($line, '-') && ! str_starts_with($line, '---')) {
                    $deletions++;
                }
            }

            $files[] = [
                'path' => Str::of($lines[0])->after(' b/')->toString(),
                'additions' => $additions,
                'deletions' => $deletions,
                'diff' => rtrim($block, "\n"),
            ];
        }

        return $files;
    }

    /**
     * Get what each test the patch adds checks, from the test methods and
     * Pest tests that appear on added lines of the app's test files.
     *
     * @return list<string>
     */
    public static function addedTests(?string $patch): array
    {
        $tests = [];

        foreach (self::files($patch) as $file) {
            if (preg_match('#(^|/)tests/.+\.php$#', $file['path']) !== 1) {
                continue;
            }

            preg_match_all('/^\+\s*(?:public\s+function\s+(test\w*)\s*\(|(it|test)\(\s*([\'"])(.+?)\3)/m', $file['diff'], $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

            foreach ($matches as $match) {
                // A Pest `it` reads as a sentence with its "it".
                $tests[] = TestMap::describe($match[1] ?? ($match[2] === 'it' ? 'it ' : '').$match[4]);
            }
        }

        return array_values(array_unique($tests));
    }

    /**
     * Get the lines a file's diff changed, numbered as in the new file or,
     * when $after is false, as in the old one. A line added on one side
     * marks the lines around the spot where it went in on the other side.
     *
     * @return list<int>
     */
    public static function changedLines(string $diff, bool $after = true): array
    {
        $lines = [];
        $number = null;
        [$here, $there] = $after ? ['+', '-'] : ['-', '+'];

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $hunk) === 1) {
                $number = (int) ($after ? $hunk[2] : $hunk[1]);

                continue;
            }

            if ($number === null || str_starts_with($line, '+++') || str_starts_with($line, '---')) {
                continue;
            }

            if (str_starts_with($line, $here)) {
                $lines[$number++] = true;
            } elseif (str_starts_with($line, $there)) {
                $lines[max(1, $number - 1)] = true;
                $lines[$number] = true;
            } elseif (str_starts_with($line, ' ')) {
                $number++;
            }
        }

        $lines = array_keys($lines);
        sort($lines);

        return $lines;
    }
}
