<?php

namespace App\VisualEditing;

/**
 * The parts an owner can add to a page in the design editor, as the plain
 * markup each starts as. The owner then writes its words and changes how
 * it looks like any other part.
 */
class NewPart
{
    /**
     * The markup of each kind of new part, by the name the builder sends.
     */
    public const MARKUP = [
        'text' => '<p>New text</p>',
        'heading' => '<h2 class="text-lg font-semibold">New heading</h2>',
        'button' => '<button type="button" class="rounded-md border px-4 py-2 text-sm font-medium">Button</button>',
    ];

    /**
     * Get the markup a new part of a kind starts as.
     */
    public static function markup(string $kind): string
    {
        return self::MARKUP[$kind];
    }

    /**
     * Get the tag a new part of a kind is written with.
     */
    public static function tag(string $kind): string
    {
        return (string) strtok(substr(self::MARKUP[$kind], 1), ' >');
    }
}
