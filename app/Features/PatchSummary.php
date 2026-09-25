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
}
