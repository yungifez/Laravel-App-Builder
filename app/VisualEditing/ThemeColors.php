<?php

namespace App\VisualEditing;

class ThemeColors
{
    /**
     * Where each look of the app writes its colours, as the change is
     * recorded: the light look in ":root", the dark look in ".dark".
     */
    public const MODES = ['light' => ':root', 'dark' => '.dark'];

    /**
     * The rules each look writes its colours in. Tailwind's `@theme` holds
     * the light look too, as does `html`.
     */
    protected const SELECTORS = [
        'light' => [':root', ':host', 'html', '@theme'],
        'dark' => ['.dark', ':root.dark', 'html.dark'],
    ];

    /**
     * A value the design panel can show and replace: a colour code or a
     * colour function. A reference to another variable is not, nor a bare
     * "222 47% 11%" that the app wraps in hsl() where it uses it.
     */
    protected const COLOR = '/^(?:#[0-9a-fA-F]{3,8}|(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\([^()]*\))$/i';

    /**
     * Find the colours an app's stylesheets write, whatever the design
     * system calls them. Each has the name its classes use ("brand-500" in
     * `bg-brand-500`), the variable that holds its value, and whether it
     * has Tailwind classes at all.
     *
     * A Tailwind colour that only points at another variable, as
     * `--color-primary: var(--primary)` does, is named by the first and
     * held by the second. A plain colour variable is named for itself and
     * has no classes, unless a Tailwind colour already has its name.
     *
     * @param  list<string>  $stylesheets
     * @return list<array{name: string, variable: string, classes: bool}>
     */
    public static function discover(array $stylesheets): array
    {
        $values = [];
        $theme = [];

        foreach ($stylesheets as $css) {
            foreach (self::blocks($css, 'light') as [$selector, $body]) {
                foreach (self::declarations($body) as [$variable, $value]) {
                    $values[$variable] ??= $value;

                    if ($selector === '@theme' && str_starts_with($variable, 'color-')) {
                        $theme[substr($variable, 6)] ??= $value;
                    }
                }
            }
        }

        $colors = [];
        $held = [];

        foreach ($theme as $name => $value) {
            $variable = preg_match('/^var\(--([A-Za-z0-9_-]+)\)$/', $value, $match) === 1 ? $match[1] : "color-{$name}";

            if (preg_match(self::COLOR, $values[$variable] ?? '') === 1) {
                $colors[] = ['name' => $name, 'variable' => $variable, 'classes' => true];
                $held[$variable] = true;
            }
        }

        foreach ($values as $variable => $value) {
            if (! isset($held[$variable]) && ! isset($theme[$variable]) && ! str_starts_with($variable, 'color-') && preg_match(self::COLOR, $value) === 1) {
                $colors[] = ['name' => $variable, 'variable' => $variable, 'classes' => false];
            }
        }

        return $colors;
    }

    /**
     * Find where one colour variable of one look is written in a
     * stylesheet, or null when it is not written there as a colour.
     *
     * @return array{offset: int, length: int, value: string}|null
     */
    public static function find(string $css, string $mode, string $variable): ?array
    {
        foreach (self::blocks($css, $mode) as [, $body, $start]) {
            foreach (self::declarations($body) as [$name, $value, $offset]) {
                if ($name === $variable && preg_match(self::COLOR, $value) === 1) {
                    return ['offset' => $start + $offset, 'length' => strlen($value), 'value' => $value];
                }
            }
        }

        return null;
    }

    /**
     * Write a new value for one colour variable of one look.
     */
    public static function write(string $css, string $mode, string $variable, string $value): ?string
    {
        $found = self::find($css, $mode, $variable);

        return $found === null ? null : substr_replace($css, $value, $found['offset'], $found['length']);
    }

    /**
     * The innermost rules of a stylesheet that write one look's colours:
     * the kind of rule ("@theme" for any Tailwind theme), its body and
     * where the body starts.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    protected static function blocks(string $css, string $mode): array
    {
        // Comments are blanked, not removed, so offsets still point into
        // the stylesheet as written. A quoted string is kept whole: a path
        // such as '../views/*.php' does not start a comment.
        $css = (string) preg_replace_callback(
            '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|\/\*.*?\*\//s',
            fn (array $match) => str_starts_with($match[0], '/*') ? str_repeat(' ', strlen($match[0])) : $match[0],
            $css,
        );

        preg_match_all('/([^{};]*)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $blocks = [];

        foreach ($rules as $rule) {
            $prelude = trim($rule[1][0]);
            $selector = str_starts_with($prelude, '@theme') ? '@theme' : null;

            foreach (array_map(trim(...), explode(',', $prelude)) as $part) {
                $selector ??= in_array($part, self::SELECTORS[$mode], true) ? $part : null;
            }

            if ($selector !== null && ($selector !== '@theme' || $mode === 'light')) {
                $blocks[] = [$selector, $rule[2][0], $rule[2][1]];
            }
        }

        return $blocks;
    }

    /**
     * The custom properties a rule's body declares: name, value and where
     * the value starts in the body.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    protected static function declarations(string $body): array
    {
        preg_match_all('/(?:^|[\s;])--([A-Za-z0-9_-]+)\s*:\s*([^;{}]*[^;{}\s])/', $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_map(fn (array $match) => [$match[1][0], $match[2][0], $match[2][1]], $matches);
    }
}
