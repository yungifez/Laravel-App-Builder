<?php

namespace App\VisualEditing;

/**
 * Words written as a quoted string in a file: a component's attribute
 * (`title="Settings"`), a value in a script (`title: 'Log in'`) or a
 * translation key (`__('Log in')`). Words a part shows through `{{ }}` are
 * often written like this in another file, where they can be changed.
 */
class QuotedWords
{
    /**
     * Find each place the words are written as a whole quoted string.
     *
     * @return list<array{offset: int, length: int, quote: string}>
     */
    public static function in(string $contents, string $words): array
    {
        if ($words === '') {
            return [];
        }

        $found = [];

        foreach (['"', "'", '`'] as $quote) {
            preg_match_all('/'.preg_quote($quote.$words.$quote, '/').'/', $contents, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                $found[] = ['offset' => $offset + 1, 'length' => strlen($words), 'quote' => $quote];
            }
        }

        // The browser tab's title is never drawn on the page, so words that
        // are also the tab's title are shown only where else they are.
        return array_values(array_filter($found, fn (array $at) => ! self::inTabTitle($contents, $at['offset'])));
    }

    /**
     * Determine whether the offset is inside a `<Head>` or `<title>` tag.
     */
    protected static function inTabTitle(string $contents, int $offset): bool
    {
        $open = strrpos(substr($contents, 0, $offset), '<');

        return $open !== false
            && ! str_contains(substr($contents, $open, $offset - $open), '>')
            && preg_match('/\G<(?:Head|title)\b/', $contents, $match, 0, $open) === 1;
    }

    /**
     * Determine whether the words can be written between the quotes as they
     * are, with nothing the code would read differently.
     */
    public static function fits(string $words, string $quote): bool
    {
        return ! str_contains($words, $quote)
            && ! str_contains($words, '\\')
            && ! str_contains($words, "\n")
            && ! ($quote === '`' && str_contains($words, '${'));
    }
}
