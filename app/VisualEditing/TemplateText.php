<?php

namespace App\VisualEditing;

/**
 * The words written directly inside an element in a Vue template. Only
 * plain words can be changed in place: no other elements among them, no
 * `{{ }}`, and no `v-text` or `v-html` on the element. Anything else
 * depends on the app, so it is left to the coding agent.
 */
class TemplateText
{
    /**
     * Find the element's words, without the white space around them.
     *
     * @return array{offset: int, length: int, value: string}|null
     */
    public static function inside(string $contents, TemplateElement $element): ?array
    {
        if ($element->selfClosing($contents)) {
            return null;
        }

        $startTag = substr($contents, $element->start, $element->end - $element->start);
        $close = strpos($contents, '<', $element->end);

        if ($close === false || preg_match('/\s(?:v-text|v-html|:innerHTML|:textContent)\b/', $startTag) === 1 || substr_compare($contents, '</'.$element->tag, $close, strlen($element->tag) + 2) !== 0) {
            return null;
        }

        $inner = substr($contents, $element->end, $close - $element->end);
        $value = trim($inner);

        if ($value === '' || str_contains($value, '{{')) {
            return null;
        }

        return [
            'offset' => $element->end + strlen($inner) - strlen(ltrim($inner)),
            'length' => strlen($value),
            'value' => $value,
        ];
    }

    /**
     * Get written words as the page shows them: runs of white space as one
     * space, and entities such as "&amp;" as the character.
     */
    public static function shown(string $written): string
    {
        return html_entity_decode((string) preg_replace('/\s+/', ' ', $written), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Write words as template text, escaping what a template would read as
     * markup.
     */
    public static function written(string $words): string
    {
        return htmlspecialchars($words, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false);
    }
}
