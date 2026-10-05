<?php

namespace App\VisualEditing;

/**
 * Words a part looks up as a translation (`__('Log in')`, `$t('auth.failed')`)
 * live in the app's translation files, not in the template. Changing them
 * there keeps the key, so every other language still finds its words.
 */
class TranslationKey
{
    protected const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Get the key a `{{ }}` looks up, or null when it looks up none.
     */
    public static function in(string $expression): ?string
    {
        return preg_match('/^(?:__|\$t|trans|t)\(\s*([\'"])([^\'"\\\\]*)\1\s*\)$/', $expression, $match) === 1 && $match[2] !== ''
            ? $match[2]
            : null;
    }

    /**
     * Get the language the app's config/app.php speaks, as Laravel's own
     * default does when it sets none.
     */
    public static function locale(?string $config): string
    {
        $pattern = '/[\'"]locale[\'"]\s*=>\s*(?:env\(\s*[\'"]APP_LOCALE[\'"]\s*,\s*)?[\'"]([A-Za-z_-]+)[\'"]/';

        return $config !== null && preg_match($pattern, $config, $match) === 1 ? $match[1] : 'en';
    }

    /**
     * Split a key such as `auth.failed` into its file and its name there.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function grouped(string $key): ?array
    {
        return preg_match('/^([\w-]+)\.([\w-]+)$/', $key, $match) === 1 ? [$match[1], $match[2]] : null;
    }

    /**
     * Find the words a JSON translation file gives the key, when it gives
     * them once.
     *
     * @return array{offset: int, length: int, value: string}|null
     */
    public static function inJson(string $contents, string $key): ?array
    {
        $name = preg_quote((string) json_encode($key, self::JSON), '/');

        preg_match_all('/'.$name.'\s*:\s*("(?:[^"\\\\]|\\\\.)*")/', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if (count($matches) !== 1) {
            return null;
        }

        [$literal, $offset] = $matches[0][1];
        $value = json_decode($literal);

        return is_string($value) ? ['offset' => $offset, 'length' => strlen($literal), 'value' => $value] : null;
    }

    /**
     * Find the words a PHP translation file gives the name, when it gives
     * them once as a plain string.
     *
     * @return array{offset: int, length: int, value: string}|null
     */
    public static function inPhp(string $contents, string $name): ?array
    {
        $pattern = '/([\'"])'.preg_quote($name, '/').'\1\s*=>\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"[^"\\\\$]*")/';

        preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if (count($matches) !== 1) {
            return null;
        }

        [$literal, $offset] = $matches[0][2];
        $inner = substr($literal, 1, -1);

        return [
            'offset' => $offset,
            'length' => strlen($literal),
            'value' => $literal[0] === "'" ? strtr($inner, ['\\\\' => '\\', "\\'" => "'"]) : $inner,
        ];
    }

    /**
     * Write words as a JSON string.
     */
    public static function json(string $words): string
    {
        return (string) json_encode($words, self::JSON);
    }

    /**
     * Write words as a PHP string.
     */
    public static function php(string $words): string
    {
        return var_export($words, true);
    }

    /**
     * Add the key with its words to a JSON translation file, in the file's
     * own indent, leaving every other line as it is.
     */
    public static function added(?string $contents, string $key, string $words): ?string
    {
        $entry = self::json($key).': '.self::json($words);
        $body = $contents === null ? '{' : rtrim($contents);
        $given = $contents === null ? [] : json_decode($contents, true);

        // A key the file gives words already, written so it cannot be
        // found, is left alone.
        if (! is_array($given) || array_key_exists($key, $given) || ($contents !== null && ! str_ends_with($body, '}'))) {
            return null;
        }

        $body = $contents === null ? '{' : rtrim(substr($body, 0, -1));
        $indent = preg_match('/\n([ \t]+)"/', $body, $match) === 1 ? $match[1] : '    ';
        $added = $body.(str_ends_with($body, '{') ? '' : ',')."\n{$indent}{$entry}\n}\n";

        return is_array(json_decode($added, true)) ? $added : null;
    }
}
