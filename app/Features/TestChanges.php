<?php

namespace App\Features;

use Illuminate\Support\Str;

class TestChanges
{
    /**
     * Find tests a patch deletes or weakens: deleted test files, and test
     * files that lose assertions or test cases.
     *
     * @return list<array{path: string, deleted: bool, removed_assertions: int}>
     */
    public static function weakened(?string $patch): array
    {
        $weakened = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (! Str::startsWith($file['path'], 'tests/') || ! Str::endsWith($file['path'], '.php')) {
                continue;
            }

            $deleted = str_contains($file['diff'], "\ndeleted file mode ");
            $removed = 0;
            $added = 0;

            foreach (explode("\n", $file['diff']) as $line) {
                $isTestLine = preg_match('/assert|expect\(|function test|\btest\(|\bit\(|#\[Test\]/i', $line) === 1;

                if ($isTestLine && str_starts_with($line, '-') && ! str_starts_with($line, '---')) {
                    $removed++;
                } elseif ($isTestLine && str_starts_with($line, '+') && ! str_starts_with($line, '+++')) {
                    $added++;
                }
            }

            if ($deleted || $removed > $added) {
                $weakened[] = ['path' => $file['path'], 'deleted' => $deleted, 'removed_assertions' => max(0, $removed - $added)];
            }
        }

        return $weakened;
    }
}
