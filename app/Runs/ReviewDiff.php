<?php

namespace App\Runs;

use App\Actions\Workspaces\DescribeEnvironment;
use App\Features\PatchSummary;

/**
 * The diff the reviewer reads: every file whole, within a length limit. A
 * lock file the package manager wrote and a file the change deletes whole
 * are named, not shown: their lines say nothing a reviewer can judge, and
 * a large one would push the change's own code out. When the rest is still
 * too long, whole files are left out, the least telling first (tests,
 * routes and app code stay), and each is named, so the reviewer knows what
 * it did not see and never mistakes a cut for a missing file.
 */
class ReviewDiff
{
    /**
     * The order in which files are kept when the diff is too long.
     */
    protected const KEEP_FIRST = ['tests/', 'routes/', 'app/', 'database/', 'config/', 'bootstrap/', 'resources/'];

    /**
     * Lay out a patch for the reviewer within "limit" characters.
     *
     * @return array{diff: string, left_out: list<string>}
     */
    public static function lay(string $patch, int $limit): array
    {
        $files = PatchSummary::files($patch);

        // Not a git diff: there are no files to tell apart.
        if ($files === []) {
            return ['diff' => self::start($patch, $limit, __('the diff')), 'left_out' => []];
        }

        $notes = [];
        $candidates = [];

        foreach ($files as $index => $file) {
            $lines = "+{$file['additions']} −{$file['deletions']} lines";

            if (in_array(basename($file['path']), DescribeEnvironment::LOCKFILES, true)) {
                $notes[$index] = __(':path: the package manager’s lock file (:lines), not shown. The packages the change asks for are in its manifest.', ['path' => $file['path'], 'lines' => $lines]);
            } elseif (preg_match('/^deleted file mode /m', $file['diff']) === 1) {
                $notes[$index] = __(':path: deleted (:count lines).', ['path' => $file['path'], 'count' => $file['deletions']]);
            } else {
                $candidates[$index] = $file;
            }
        }

        uksort($candidates, fn (int $a, int $b) => [self::rank($candidates[$a]['path']), $a] <=> [self::rank($candidates[$b]['path']), $b]);

        $shown = [];
        $used = 0;

        foreach ($candidates as $index => $file) {
            $length = mb_strlen($file['diff']) + 1;

            if ($used + $length <= $limit) {
                $shown[$index] = $file['diff'];
                $used += $length;
            } else {
                $notes[$index] = __(':path (:lines): left out for length.', ['path' => $file['path'], 'lines' => "+{$file['additions']} −{$file['deletions']} lines"]);
            }
        }

        // Every file is longer than the limit by itself: the start of the
        // one that tells most is still better than nothing.
        if ($shown === [] && $candidates !== []) {
            $index = array_key_first($candidates);
            unset($notes[$index]);
            $shown[$index] = self::start($candidates[$index]['diff'], $limit, $candidates[$index]['path']);
        }

        ksort($shown);
        ksort($notes);

        return [
            'diff' => implode("\n", $shown),
            'left_out' => array_values($notes),
        ];
    }

    /**
     * Keep the start of something longer than the limit by itself: it is
     * still better than nothing.
     */
    protected static function start(string $text, int $limit, string $what): string
    {
        return mb_strlen($text) > $limit
            ? mb_substr($text, 0, $limit)."\n… (".__('the rest of :what is left out for length', ['what' => $what]).')'
            : $text;
    }

    protected static function rank(string $path): int
    {
        foreach (self::KEEP_FIRST as $rank => $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $rank;
            }
        }

        return count(self::KEEP_FIRST);
    }
}
