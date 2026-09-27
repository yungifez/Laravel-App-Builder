<?php

namespace App\VisualEditing;

/**
 * Where a link in a Vue template goes, when it is written as a plain
 * `href="…"`. A bound `:href` depends on the app, so it is left to the
 * coding agent.
 */
class TemplateLink
{
    /**
     * Find the link's address in the element's start tag.
     *
     * @return array{offset: int, length: int, value: string}|null
     */
    public static function in(string $contents, TemplateElement $element): ?array
    {
        $startTag = substr($contents, $element->start, $element->end - $element->start);

        // A "\s" before "href" leaves out ":href" and "v-bind:href".
        if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/s', $startTag, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [
            'offset' => $element->start + $match[2][1],
            'length' => strlen($match[2][0]),
            'value' => html_entity_decode($match[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    /**
     * Determine whether an address is one a link may go to: a page of the
     * app, a place on the page, a website, an email or a phone number.
     */
    public static function allowed(string $address): bool
    {
        return preg_match('~^(/(?!/)|#|https?://[^\s/]|mailto:|tel:)~i', $address) === 1
            && preg_match('/[\s"\'<>`]|\{\{/', $address) !== 1;
    }

    /**
     * Write an address as an attribute value.
     */
    public static function written(string $address): string
    {
        return htmlspecialchars($address, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }
}
