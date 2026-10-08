<?php

namespace App\Context;

/**
 * Read the names an app's code already uses, so a plan or a test uses them
 * instead of making up new ones: the data each controller passes to its
 * page (such as "can.deleteTeam") and each model's relations. Read from
 * the code alone; nothing here asks a model or runs the app.
 */
class AreaNames
{
    /**
     * Get one line per page a controller renders, with the data it passes,
     * such as "TeamController@show passes Teams/Show: team, can.deleteTeam".
     *
     * @return list<string>
     */
    public static function pages(string $path, string $contents): array
    {
        preg_match_all('/\b(?:Inertia::render|inertia|view)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*/', $contents, $calls, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $lines = [];

        foreach ($calls as $call) {
            $after = $call[0][1] + strlen($call[0][0]);
            $keys = str_starts_with(substr($contents, $after, 8), 'compact(')
                ? self::compacted(substr($contents, $after))
                : self::keys(self::array(substr($contents, $after)));

            if ($keys === []) {
                continue;
            }

            $method = preg_match_all('/function\s+(\w+)\s*\(/', substr($contents, 0, $call[0][1]), $methods) > 0 ? '@'.end($methods[1]) : '';
            $lines[] = basename($path, '.php')."{$method} passes {$call[1][0]}: ".implode(', ', $keys);
        }

        return $lines;
    }

    /**
     * Get the relation methods a model declares, such as "Team: members()
     * is belongsToMany(User)".
     *
     * @return list<string>
     */
    public static function relations(string $path, string $contents): array
    {
        preg_match_all('/function\s+(\w+)\s*\([^)]*\)[^{]*\{\s*return\s+\$this->(hasOne|hasMany|belongsTo|belongsToMany|hasOneThrough|hasManyThrough|morphTo|morphOne|morphMany|morphToMany|morphedByMany)\(\s*(?:\\\\?(?:[\w\\\\]+\\\\)?(\w+)::class)?/', $contents, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match) => basename($path, '.php').": {$match[1]}() is {$match[2]}(".($match[3] ?? '').')',
            $matches,
        );
    }

    /**
     * Get the array literal a text starts with, without its outer
     * brackets, or '' when it starts with none or it never closes.
     */
    protected static function array(string $text): string
    {
        if (! str_starts_with($text, '[')) {
            return '';
        }

        $depth = 0;
        $quote = null;

        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $char = $text[$index];

            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[' || $char === '(') {
                $depth++;
            } elseif (($char === ']' || $char === ')') && --$depth === 0) {
                return substr($text, 1, $index - 1);
            }
        }

        return '';
    }

    /**
     * Get the keys of an array literal's body, with the keys of an array
     * under a key as "key.inner".
     *
     * @return list<string>
     */
    protected static function keys(string $body): array
    {
        $keys = [];

        while (preg_match('/^\s*[\'"]([\w.-]+)[\'"]\s*=>\s*/', $body, $key) === 1) {
            $value = substr($body, strlen($key[0]));
            $inner = self::array($value);
            $nested = $inner === '' ? [] : self::keys($inner);
            array_push($keys, ...($nested === [] ? [$key[1]] : array_map(fn (string $name) => "{$key[1]}.{$name}", $nested)));
            $body = self::rest($value);
        }

        return $keys;
    }

    /**
     * Get what follows the first value of an array literal's body, after
     * its comma.
     */
    protected static function rest(string $text): string
    {
        $depth = 0;
        $quote = null;

        for ($index = 0, $length = strlen($text); $index < $length; $index++) {
            $char = $text[$index];

            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif (in_array($char, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($char, [']', ')', '}'], true)) {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                return substr($text, $index + 1);
            }
        }

        return '';
    }

    /**
     * Get the names a compact() call passes.
     *
     * @return list<string>
     */
    protected static function compacted(string $text): array
    {
        preg_match('/^compact\(([^)]*)\)/', $text, $call);
        preg_match_all('/[\'"](\w+)[\'"]/', $call[1] ?? '', $names);

        return $names[1];
    }
}
