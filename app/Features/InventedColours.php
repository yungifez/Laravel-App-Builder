<?php

namespace App\Features;

/**
 * Colours a change makes up on the lines it adds to the app's screens,
 * instead of using the app's own theme colours (direction 26: token use
 * instead of invented values). A made-up colour is how a design slowly
 * drifts: each one is close to a theme colour, and none of them follows
 * the theme when it changes.
 *
 * Only added lines count, so colours the app already had are never held
 * against a change. A line is let through when it, or the line above it,
 * carries a comment that says why that colour is meant.
 */
class InventedColours
{
    /**
     * The screen files the scan reads. The theme itself lives in CSS, which
     * is where a new colour belongs.
     */
    protected const FILES = '/^(?!tests\/).*\.(vue|blade\.php|tsx|jsx)$/';

    /**
     * A literal colour: a hex value (not an HTML entity) or a colour function.
     */
    protected const COLOUR = '(?:(?<![&\w])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\()';

    /**
     * Each way a colour gets made up: as a Tailwind arbitrary value, or in
     * an inline style.
     *
     * @var array<string, string>
     */
    protected const PATTERNS = [
        'class' => '/-\[(?:color:)?'.self::COLOUR.'/',
        'style' => '/\bstyle\b.*'.self::COLOUR.'/',
    ];

    /**
     * A comment that says why a colour is meant. Only comment forms screen
     * files use, since "#" starts a colour here.
     */
    protected const REASON_COMMENT = '/(\/\/|\/\*|\{\{--|<!--).*\b(colou?rs?|brand)\b/i';

    /**
     * Find the made-up colours on the lines the patch adds, numbered as in
     * the new file, once per file at its first.
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

            foreach (PatchSummary::addedLines($file['diff']) as $added) {
                if (preg_match(self::REASON_COMMENT, $added['text'].' '.$added['previous']) === 1) {
                    continue;
                }

                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $added['text']) === 1) {
                        $found[] = ['path' => $file['path'], 'line' => $added['line']];

                        continue 3;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Say what is wrong with a made-up colour and how to fix it, for the
     * coder that must fix it.
     *
     * @param  array{path: string, line: int}  $found
     */
    public static function finding(array $found): string
    {
        return __('Line :line of :path makes up a colour instead of using one of the app\'s theme colours, so it will not follow the theme. Use a colour from the app\'s theme, or add the colour to the theme in the app\'s CSS and use it from there. If this exact colour is meant, say why in a comment that mentions the colour, on that line or the line above.', $found);
    }

    /**
     * Determine if the patch changes screen files, so a clean scan says
     * something.
     */
    public static function scans(?string $patch): bool
    {
        foreach (PatchSummary::files($patch) as $file) {
            if (preg_match(self::FILES, $file['path']) === 1) {
                return true;
            }
        }

        return false;
    }
}
