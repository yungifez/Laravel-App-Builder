<?php

namespace App\Features;

/**
 * Pictures a change adds to the app's screens without saying what they
 * show (direction 26: accessibility, checked as far as structure allows).
 * People who cannot see the screen hear the description instead; a
 * picture that is only decoration says so with an empty one (alt="").
 *
 * Only added tags count, so pictures the app already had are never held
 * against a change. A tag whose attributes are spread in from elsewhere
 * is unknown, not undescribed, so it is let through.
 */
class UndescribedImages
{
    /**
     * The screen files the scan reads.
     */
    protected const FILES = '/^(?!tests\/).*\.(vue|blade\.php|tsx|jsx)$/';

    /**
     * Find the picture tags the patch adds without a description, numbered
     * as in the new file, once per file at its first.
     *
     * @return list<array{path: string, line: int}>
     */
    public static function found(?string $patch): array
    {
        $found = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (preg_match(self::FILES, $file['path']) !== 1 || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            $added = PatchSummary::addedLines($file['diff']);

            foreach ($added as $index => $line) {
                foreach (self::tags($added, $index) as $tag) {
                    if (! preg_match('/\balt\s*=|\{\s*\.\.\.|\bv-bind\s*=|\{\{\s*\$attributes/i', $tag)) {
                        $found[] = ['path' => $file['path'], 'line' => $line['line']];

                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Get each picture tag that starts on an added line, read on across
     * the added lines right after it until the tag closes. A tag that runs
     * into a line the change did not add is left out: part of it is
     * unknown.
     *
     * @param  list<array{line: int, text: string, previous: string}>  $added
     * @return list<string>
     */
    protected static function tags(array $added, int $index): array
    {
        $tags = [];
        $text = $added[$index]['text'];
        $offset = 0;

        while (preg_match('/<img\b/i', $text, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $tag = substr($text, $start);
            $next = $index;

            // Arrows (=> in Vue, -> in Blade) do not close a tag.
            while (preg_match('/(?<![=-])>/', $tag, $close, PREG_OFFSET_CAPTURE) !== 1) {
                $next++;

                if (! isset($added[$next]) || $added[$next]['line'] !== $added[$next - 1]['line'] + 1) {
                    return $tags;
                }

                $tag .= ' '.$added[$next]['text'];
            }

            $tags[] = substr($tag, 0, $close[0][1]);
            $offset = $start + 4;
        }

        return $tags;
    }

    /**
     * Say what is wrong with an undescribed picture and how to fix it, for
     * the coder that must fix it.
     *
     * @param  array{path: string, line: int}  $found
     */
    public static function finding(array $found): string
    {
        return __('Line :line of :path adds a picture (<img>) without an alt description, so people who cannot see the screen do not know what it shows. Add alt with a short description of what it shows, or alt="" when it is only decoration.', $found);
    }

    /**
     * Determine if the patch adds a picture tag to a screen, so a clean
     * scan says something.
     */
    public static function scans(?string $patch): bool
    {
        foreach (PatchSummary::files($patch) as $file) {
            if (preg_match(self::FILES, $file['path']) === 1) {
                foreach (PatchSummary::addedLines($file['diff']) as $line) {
                    if (preg_match('/<img\b/i', $line['text']) === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
