<?php

namespace App\VisualEditing;

/**
 * Reads the names an app's Tailwind `@theme` gives its scales, such as
 * "hero" for `--text-hero`, so class clashes are judged by the app's own
 * theme (`text-hero` is a size, not a colour).
 */
class TailwindTheme
{
    /**
     * The scales tailwind-merge knows, by the start of their variables.
     * Longer starts come first: `--font-weight-bold` is not a font.
     * Colours are the app's colours (see ThemeColors), and spacing is a
     * number, so neither is here.
     */
    protected const SCALES = [
        'inset-shadow' => 'inset-shadow',
        'drop-shadow' => 'drop-shadow',
        'font-weight' => 'font-weight',
        'perspective' => 'perspective',
        'breakpoint' => 'breakpoint',
        'container' => 'container',
        'tracking' => 'tracking',
        'leading' => 'leading',
        'animate' => 'animate',
        'radius' => 'radius',
        'shadow' => 'shadow',
        'aspect' => 'aspect',
        'text' => 'text',
        'font' => 'font',
        'blur' => 'blur',
        'ease' => 'ease',
    ];

    /**
     * Find the names each scale has in the stylesheets' `@theme` blocks.
     *
     * @param  list<string>  $stylesheets
     * @return array<string, list<string>>
     */
    public static function discover(array $stylesheets): array
    {
        $names = [];

        foreach ($stylesheets as $css) {
            foreach (self::themeBodies($css) as $body) {
                // Only the block's own variables: `--text-hero--line-height`
                // and `--color-*: initial` name no class.
                preg_match_all('/(?:^|[;{\s])--([a-z0-9]+(?:-[a-z0-9]+)*)\s*:/i', $body, $matches);

                foreach ($matches[1] as $variable) {
                    foreach (self::SCALES as $start => $scale) {
                        if (str_starts_with($variable, "{$start}-")) {
                            $names[$scale][] = substr($variable, strlen($start) + 1);

                            break;
                        }
                    }
                }
            }
        }

        return array_map(fn (array $scale) => array_values(array_unique($scale)), $names);
    }

    /**
     * Get the insides of each `@theme` block, without the blocks nested in
     * them, such as `@keyframes`.
     *
     * @return list<string>
     */
    protected static function themeBodies(string $css): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $bodies = [];

        preg_match_all('/@theme\b[^{;]*\{/', $css, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[0] as [$start, $offset]) {
            $body = '';
            $depth = 1;

            for ($i = $offset + strlen($start); $i < strlen($css) && $depth > 0; $i++) {
                $depth += match ($css[$i]) {
                    '{' => 1,
                    '}' => -1,
                    default => 0,
                };

                if ($depth === 1 && $css[$i] !== '}') {
                    $body .= $css[$i];
                }
            }

            $bodies[] = $body;
        }

        return $bodies;
    }
}
