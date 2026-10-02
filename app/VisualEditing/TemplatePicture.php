<?php

namespace App\VisualEditing;

/**
 * Which file a picture in a Vue template or a Blade view shows, when it is written as a
 * plain `src="…"`. A bound `:src` depends on the app, so it is left to the
 * coding agent.
 */
class TemplatePicture
{
    /**
     * The kinds of picture an owner may put in, by the extension they are
     * written with.
     */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * Find the picture's file in the element's start tag.
     *
     * @return array{offset: int, length: int, value: string}|null
     */
    public static function in(string $contents, TemplateElement $element): ?array
    {
        if ($element->tag !== 'img') {
            return null;
        }

        $startTag = substr($contents, $element->start, $element->end - $element->start);

        // A "\s" before "src" leaves out ":src", "v-bind:src" and "srcset".
        // One printed by Blade ("{{ asset('logo.png') }}") is the app's to choose.
        if (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/s', $startTag, $match, PREG_OFFSET_CAPTURE) !== 1 || preg_match('/\{\{|\{!!/', $match[2][0]) === 1) {
            return null;
        }

        return [
            'offset' => $element->start + $match[2][1],
            'length' => strlen($match[2][0]),
            'value' => html_entity_decode($match[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    /**
     * Where a new picture is kept in the app, named by its contents, so the
     * same picture put in twice is one file.
     */
    public static function path(string $contents, string $extension): string
    {
        return 'public/images/'.substr(hash('sha256', $contents), 0, 16).'.'.strtolower($extension);
    }

    /**
     * The address the app serves a kept picture at.
     */
    public static function address(string $path): string
    {
        return '/'.substr($path, strlen('public/'));
    }
}
