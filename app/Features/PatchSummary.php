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

    /**
     * Rebuild a file as it was before its diff, from the file as the diff
     * leaves it. Null when the diff adds the file, or when it does not fit
     * the file it is said to have made.
     */
    public static function before(string $after, string $diff): ?string
    {
        if (str_contains($diff, "\nnew file mode ") || str_contains($diff, "\n--- /dev/null")) {
            return null;
        }

        $new = explode("\n", $after);
        $old = [];
        $next = 1;
        $inHunk = false;

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $hunk) === 1) {
                // A hunk that only removes lines starts after the line it names.
                $start = (int) $hunk[1] + (($hunk[2] ?? null) === '0' ? 1 : 0);

                if ($start < $next) {
                    return null;
                }

                array_push($old, ...array_slice($new, $next - 1, $start - $next));
                $next = $start;
                $inHunk = true;

                continue;
            }

            if (! $inHunk || str_starts_with($line, '\\')) {
                continue;
            }

            if (str_starts_with($line, '-')) {
                $old[] = substr($line, 1);
            } elseif (str_starts_with($line, '+') || str_starts_with($line, ' ') || $line === '') {
                if (! str_starts_with($line, '+')) {
                    $old[] = substr($line, 1);
                }

                $next++;
            }
        }

        array_push($old, ...array_slice($new, $next - 1));

        return implode("\n", $old);
    }

    /**
     * Get the lines a file's diff adds, numbered as in the new file, each
     * with the line above it (added or kept), where a comment about it
     * would sit.
     *
     * @return list<array{line: int, text: string, previous: string}>
     */
    public static function addedLines(string $diff): array
    {
        $added = [];
        $inHunk = false;
        $number = 0;
        $previous = '';

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $line, $match) === 1) {
                $inHunk = true;
                $number = (int) $match[1] - 1;
                $previous = '';

                continue;
            }

            // File headers, removed lines and "no newline" notes are not in the new file.
            if (! $inHunk || str_starts_with($line, '-') || str_starts_with($line, '\\')) {
                continue;
            }

            $number++;
            $text = substr($line, 1);

            if (str_starts_with($line, '+')) {
                $added[] = ['line' => $number, 'text' => $text, 'previous' => $previous];
            }

            $previous = $text;
        }

        return $added;
    }
}
