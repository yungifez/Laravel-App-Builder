<?php

namespace App\VisualEditing;

class ThemeColors
{
    /**
     * The theme colours an owner can change: the ones the design panel
     * offers as choices, written as CSS variables such as "--primary".
     */
    public const TOKENS = [
        'background', 'foreground', 'card', 'primary', 'primary-foreground', 'secondary', 'secondary-foreground',
        'muted', 'muted-foreground', 'accent', 'accent-foreground', 'border', 'input', 'destructive',
    ];

    /**
     * Where each look of the app writes its colours: the light look in
     * ":root", the dark look in ".dark", as Tailwind themes do.
     */
    public const MODES = ['light' => ':root', 'dark' => '.dark'];

    /**
     * Find where one colour of one look is written in a stylesheet, or null
     * when it is not written there.
     *
     * @return array{offset: int, length: int, value: string}|null
     */
    public static function find(string $css, string $mode, string $token): ?array
    {
        $selector = preg_quote(self::MODES[$mode], '/');

        if (preg_match('/(?:^|[\s};])'.$selector.'\s*\{([^{}]*)\}/', $css, $block, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$body, $start] = $block[1];

        if (preg_match('/(?:^|[\s;])--'.preg_quote($token, '/').'\s*:\s*([^;{}]*[^;{}\s])/', $body, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return ['offset' => $start + $match[1][1], 'length' => strlen($match[1][0]), 'value' => $match[1][0]];
    }

    /**
     * Write a new value for one colour of one look.
     */
    public static function write(string $css, string $mode, string $token, string $value): ?string
    {
        $found = self::find($css, $mode, $token);

        return $found === null ? null : substr_replace($css, $value, $found['offset'], $found['length']);
    }
}
