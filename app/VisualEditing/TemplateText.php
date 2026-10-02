<?php

namespace App\VisualEditing;

/**
 * The words written directly inside an element in a Vue template or a
 * Blade view. Only plain words can be changed in place: no other elements
 * among them, no `{{ }}`, `{!! !!}` or Blade directive, and no `v-text`,
 * `v-html` or `x-html` on the element. Anything else
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

        if ($close === false || preg_match('/\s(?:v-text|v-html|x-text|x-html|:innerHTML|:textContent)\b/', $startTag) === 1 || substr_compare($contents, '</'.$element->tag, $close, strlen($element->tag) + 2) !== 0) {
            return null;
        }

        $inner = substr($contents, $element->end, $close - $element->end);
        $value = trim($inner);

        // Words printed by Blade ({!! !!}, @lang and the like) are the app's.
        if ($value === '' || str_contains($value, '{{') || str_contains($value, '{!!') || preg_match('/(?<![\w@])@(?:if|unless|isset|foreach|for|forelse|else|php|lang|choice|json|csrf|include|auth|guest|can|env)\b/', $value) === 1) {
            return null;
        }

        return [
            'offset' => $element->end + strlen($inner) - strlen(ltrim($inner)),
            'length' => strlen($value),
            'value' => $value,
        ];
    }

    /**
     * Get what the element shows when it is a single `{{ }}` that names a
     * value (`title`, `item.title`) or looks up a translation (`__('Log
     * in')`): those words are written somewhere else as they are.
     */
    public static function named(string $contents, TemplateElement $element): ?string
    {
        if ($element->selfClosing($contents)) {
            return null;
        }

        $close = strpos($contents, '</'.$element->tag, $element->end);
        $inner = $close === false ? '' : trim(substr($contents, $element->end, $close - $element->end));

        if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/s', $inner, $match) !== 1) {
            return null;
        }

        $name = '[A-Za-z_$][\w$]*';
        $plain = preg_match('/^'.$name.'(?:\??\.'.$name.')*$/', $match[1]) === 1;
        $translated = preg_match('/^(?:__|\$t|trans|t)\(\s*([\'"])[^\'"\\\\]*\1\s*\)$/', $match[1]) === 1;

        return $plain || $translated ? $match[1] : null;
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
