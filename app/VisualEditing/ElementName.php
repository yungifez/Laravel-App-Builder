<?php

namespace App\VisualEditing;

use Illuminate\Support\Str;

/**
 * An element's tag in words, for commit subjects the owner reads in the
 * app's history: "<h2>" is "a heading", "<CardTitle>" is "a card title".
 */
class ElementName
{
    /**
     * The words for each HTML tag, as the design panel names parts
     * (resources/js/lib/partKinds.ts).
     *
     * @var array<string, string>
     */
    protected const WORDS = [
        'a' => 'a link',
        'link' => 'a link',
        'button' => 'a button',
        'p' => 'some text',
        'span' => 'some text',
        'ul' => 'a list',
        'ol' => 'a list',
        'li' => 'an item',
        'img' => 'a picture',
        'svg' => 'a picture',
        'picture' => 'a picture',
        'video' => 'a video',
        'nav' => 'the menu',
        'header' => 'the top of the page',
        'footer' => 'the bottom of the page',
        'main' => 'the main area',
        'form' => 'a form',
        'input' => 'a field',
        'textarea' => 'a field',
        'select' => 'a field',
        'label' => 'a label',
        'table' => 'a table',
        'div' => 'a box',
        'section' => 'a box',
        'article' => 'a box',
        'aside' => 'a box',
    ];

    /**
     * Get the words for the tag.
     */
    public static function for(string $tag): string
    {
        if (preg_match('/^h[1-6]$/i', $tag) === 1) {
            return 'a heading';
        }

        if (isset(self::WORDS[strtolower($tag)])) {
            return self::WORDS[strtolower($tag)];
        }

        // A component's name says what it is: "CardTitle" is a card title.
        $words = strtolower(trim(preg_replace('/[^A-Za-z]+/', ' ', Str::snake($tag, ' ')) ?? ''));

        if ($words === '') {
            return 'a box';
        }

        return (preg_match('/^[aeiou]/', $words) === 1 ? 'an ' : 'a ').$words;
    }
}
