<?php

namespace App\Features;

/**
 * What the screen check measured when it opened the app's pages at phone,
 * tablet and computer widths (direction 26), read against a change.
 *
 * Only pages whose screen file the change touched are held against it, so
 * a problem the app already had elsewhere never sends a change back. A
 * page names its screen through Inertia's page component; a page that
 * does not (a Blade view, say) is measured but never blamed.
 */
class ScreenCheck
{
    /**
     * The files that shape a screen: a change to one is worth measuring.
     */
    protected const FILES = '/^(?!tests\/).*\.(vue|blade\.php|tsx|jsx|svelte|css)$/';

    /**
     * Determine if the patch touches a screen, so the check is worth running.
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

    /**
     * Get the screens the patch touches, named as Inertia names them, so
     * the check takes pictures of them.
     *
     * @return list<string>
     */
    public static function shootable(?string $patch, int $max): array
    {
        $screens = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (! str_contains($file['diff'], "\ndeleted file mode ") && preg_match('#(?:^|/)pages/(.+)\.(vue|tsx|jsx|svelte)$#i', $file['path'], $match) === 1) {
                $screens[] = $match[1];
            }
        }

        return array_slice(array_values(array_unique($screens)), 0, $max);
    }

    /**
     * Read the measuring tool's report, or null when it is not one.
     *
     * @return array{pages: list<array<string, mixed>>, signed_in?: bool}|null
     */
    public static function parse(string $report): ?array
    {
        $screens = json_decode(trim($report), true);

        if (! is_array($screens) || ! isset($screens['pages']) || ! is_array($screens['pages']) || ! array_is_list($screens['pages'])) {
            return null;
        }

        return ['pages' => $screens['pages'], 'signed_in' => (bool) ($screens['signed_in'] ?? false)];
    }

    /**
     * Get the measured pages whose screen file the change touched, once
     * per screen, each with that file.
     *
     * @param  array{pages: list<array<string, mixed>>, signed_in?: bool}|null  $screens
     * @return list<array{page: array<string, mixed>, file: string}>
     */
    public static function changed(?array $screens, ?string $patch): array
    {
        $files = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (! str_contains($file['diff'], "\ndeleted file mode ")) {
                $files[] = $file['path'];
            }
        }

        $changed = [];

        foreach ($screens['pages'] ?? [] as $page) {
            $screen = $page['screen'] ?? null;

            if (! is_string($screen) || $screen === '' || isset($changed[$screen])) {
                continue;
            }

            $pattern = '#(^|/)pages/'.preg_quote($screen, '#').'\.(vue|tsx|jsx|svelte)$#i';

            foreach ($files as $path) {
                if (preg_match($pattern, $path) === 1) {
                    $changed[$screen] = ['page' => $page, 'file' => $path];

                    break;
                }
            }
        }

        return array_values($changed);
    }

    /**
     * Find what is wrong on the pages the change touched: each kind of
     * problem once per page, at the narrowest width it shows.
     *
     * @param  array{pages: list<array<string, mixed>>, signed_in?: bool}|null  $screens
     * @return list<array{kind: string, path: string, file: string, width: int, detail: string, size: int}>
     */
    public static function found(?array $screens, ?string $patch): array
    {
        $found = [];

        foreach (self::changed($screens, $patch) as ['page' => $page, 'file' => $file]) {
            $seen = [];
            /** @var list<array<string, mixed>> $widths */
            $widths = is_array($page['widths'] ?? null) ? $page['widths'] : [];
            usort($widths, fn (array $a, array $b) => ($a['width'] ?? 0) <=> ($b['width'] ?? 0));

            foreach ($widths as $measured) {
                $width = (int) ($measured['width'] ?? 0);
                $problems = [
                    'cut' => isset($measured['cut'][0]) ? [(string) $measured['cut'][0]['text'], (int) $measured['cut'][0]['past']] : null,
                    'scroll' => ($measured['overflow'] ?? 0) > 1 ? ['', (int) $measured['overflow']] : null,
                    'small' => isset($measured['small'][0]) ? [(string) $measured['small'][0]['text'], (int) min($measured['small'][0]['width'], $measured['small'][0]['height'])] : null,
                    'error' => isset($measured['errors'][0]) ? [(string) $measured['errors'][0], 0] : null,
                ];

                foreach ($problems as $kind => $problem) {
                    if ($problem === null || isset($seen[$kind])) {
                        continue;
                    }

                    $seen[$kind] = true;
                    $found[] = ['kind' => $kind, 'path' => (string) $page['path'], 'file' => $file, 'width' => $width, 'detail' => $problem[0], 'size' => $problem[1]];
                }
            }
        }

        return $found;
    }

    /**
     * Get the words too faint to read (WCAG 2.2 AA contrast) on the pages
     * the change touched, once each. They are said to the owner as a gap,
     * never a send-back: faint colours usually come from the app's shared
     * theme, which a change to one screen should not rewrite.
     *
     * @param  array{pages: list<array<string, mixed>>, signed_in?: bool}|null  $screens
     * @return list<array{path: string, file: string, text: string, ratio: float}>
     */
    public static function faint(?array $screens, ?string $patch): array
    {
        $faint = [];

        foreach (self::changed($screens, $patch) as ['page' => $page, 'file' => $file]) {
            foreach (is_array($page['widths'] ?? null) ? $page['widths'] : [] as $measured) {
                foreach (is_array($measured['faint'] ?? null) ? $measured['faint'] : [] as $words) {
                    $faint[$words['text']] ??= ['path' => (string) $page['path'], 'file' => $file, 'text' => (string) $words['text'], 'ratio' => (float) $words['ratio']];
                }
            }
        }

        return array_values($faint);
    }

    /**
     * Get the controls that do not show when the keyboard reaches them
     * (WCAG 2.2 AA 2.4.7) on the pages the change touched, once each. Like
     * faint words, they are a gap, never a send-back: focus styles usually
     * come from the app's shared components.
     *
     * @param  array{pages: list<array<string, mixed>>, signed_in?: bool}|null  $screens
     * @return list<array{path: string, file: string, text: string}>
     */
    public static function unfocused(?array $screens, ?string $patch): array
    {
        $unfocused = [];

        foreach (self::changed($screens, $patch) as ['page' => $page, 'file' => $file]) {
            foreach (is_array($page['widths'] ?? null) ? $page['widths'] : [] as $measured) {
                foreach (is_array($measured['unfocused'] ?? null) ? $measured['unfocused'] : [] as $control) {
                    $unfocused[$control['text']] ??= ['path' => (string) $page['path'], 'file' => $file, 'text' => (string) $control['text']];
                }
            }
        }

        return array_values($unfocused);
    }

    /**
     * Say what is wrong on a page and how to fix it, for the coder that
     * must fix it.
     *
     * @param  array{kind: string, path: string, file: string, width: int, detail: string, size: int}  $found
     */
    public static function finding(array $found): string
    {
        return match ($found['kind']) {
            'cut' => __('At :width px wide, ":detail" on :path (:file) runs :size px past the edge of the screen, so part of it cannot be seen or reached. Make it fit that width: let it wrap, shrink or stack, or scroll inside its own box.', $found),
            'scroll' => __('At :width px wide, :path (:file) scrolls sideways by :size px. Make the page fit that width.', $found),
            'small' => __('At :width px wide, ":detail" on :path (:file) is :size px across and too close to other controls to tap reliably. Make each control at least 24 by 24 px (44 is better on phones), or give it more space.', $found),
            default => __('Opening :path (:file) at :width px wide threw a script error: :detail', $found),
        };
    }
}
