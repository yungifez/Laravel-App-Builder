<?php

namespace App\VisualEditing;

/**
 * An element's start tag in a Vue template, found at the line and column the
 * preview's source locator stamped, and its classes when they can be edited
 * in place.
 *
 * Classes are editable when they are written as a static `class="…"`, or as
 * the first string of `:class="cn('…', …)"`. Any other `:class` binding
 * depends on the app's state, so it is left to the coding agent.
 */
class TemplateElement
{
    /**
     * @param  string  $tag  The tag as written, such as "div" or "Button"
     * @param  int  $start  Offset of the tag's "<"
     * @param  int  $end  Offset just after the start tag's ">"
     * @param  array{offset: int, length: int, value: string}|null  $classes  Where the editable classes are
     * @param  bool  $dynamic  Whether the classes also depend on a binding that is not editable
     */
    public function __construct(
        public string $tag,
        public int $start,
        public int $end,
        public ?array $classes,
        public bool $dynamic,
    ) {}

    /**
     * Find the element whose "<" is at the line and column (both from 1).
     */
    public static function at(string $contents, int $line, int $column): ?self
    {
        $offset = self::offset($contents, $line, $column);

        if ($offset === null || ($contents[$offset] ?? '') !== '<' || preg_match('/\G<([A-Za-z][\w.:-]*)/', $contents, $match, 0, $offset) !== 1) {
            return null;
        }

        $tag = $match[1];
        $position = $offset + strlen($match[0]);
        $length = strlen($contents);
        $static = null;
        $bound = null;

        // Read the attributes up to the tag's closing ">", honouring quotes.
        while ($position < $length) {
            if (preg_match('/\G\s*(\/?>)/', $contents, $close, 0, $position) === 1) {
                $end = $position + strlen($close[0]);

                return new self($tag, $offset, $end, $static ?? self::fromClassHelper($bound), $bound !== null && ($static !== null || self::fromClassHelper($bound) === null));
            }

            if (preg_match('/\G\s*([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?/', $contents, $attribute, PREG_OFFSET_CAPTURE, $position) !== 1) {
                return null;
            }

            $name = $attribute[1][0];
            $value = self::captured($attribute);

            if ($name === 'class' && $value !== null) {
                $static = $value;
            } elseif (in_array($name, [':class', 'v-bind:class'], true) && $value !== null) {
                $bound = $value;
            }

            $position += strlen($attribute[0][0]);
        }

        return null;
    }

    /**
     * Get the contents with the element's editable classes replaced, adding
     * a static class attribute when the element has none.
     */
    public function withClasses(string $contents, string $classes): string
    {
        if ($this->classes !== null) {
            return substr_replace($contents, $classes, $this->classes['offset'], $this->classes['length']);
        }

        $afterTag = $this->start + 1 + strlen($this->tag);

        return substr_replace($contents, ' class="'.$classes.'"', $afterTag, 0);
    }

    /**
     * Determine whether the element's classes can be changed in place.
     */
    public function editable(): bool
    {
        return ! $this->dynamic;
    }

    /**
     * Get the offset of a line and column (both from 1).
     */
    protected static function offset(string $contents, int $line, int $column): ?int
    {
        if ($line < 1 || $column < 1) {
            return null;
        }

        $offset = 0;

        for ($current = 1; $current < $line; $current++) {
            $next = strpos($contents, "\n", $offset);

            if ($next === false) {
                return null;
            }

            $offset = $next + 1;
        }

        $offset += $column - 1;

        return $offset < strlen($contents) ? $offset : null;
    }

    /**
     * Get an attribute's quoted or bare value and where it is.
     *
     * @param  array<int, array{0: string, 1: int}>  $attribute
     * @return array{offset: int, length: int, value: string}|null
     */
    protected static function captured(array $attribute): ?array
    {
        foreach ([2, 3, 4] as $group) {
            if (isset($attribute[$group]) && $attribute[$group][1] >= 0) {
                return ['offset' => $attribute[$group][1], 'length' => strlen($attribute[$group][0]), 'value' => $attribute[$group][0]];
            }
        }

        return null;
    }

    /**
     * Get the first string passed to cn() in a class binding, when the
     * binding is `cn('…', …)` and that string is a plain literal.
     *
     * @param  array{offset: int, length: int, value: string}|null  $binding
     * @return array{offset: int, length: int, value: string}|null
     */
    protected static function fromClassHelper(?array $binding): ?array
    {
        if ($binding === null || preg_match('/^\s*cn\(\s*\'([^\'\\\\]*)\'/', $binding['value'], $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return ['offset' => $binding['offset'] + $match[1][1], 'length' => strlen($match[1][0]), 'value' => $match[1][0]];
    }
}
