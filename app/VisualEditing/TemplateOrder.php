<?php

namespace App\VisualEditing;

use InvalidArgumentException;

/**
 * The order of elements in a Vue template, and moving one element before or
 * after a sibling: the owner dragging a part to a new place on the page.
 *
 * The template is read tag by tag, skipping comments and `{{ … }}` text,
 * so every element's whole extent (start tag to end tag) and parent are
 * known. Only siblings (elements with the same parent) change places, and
 * never in a way that splits a `v-if` from its `v-else`.
 */
class TemplateOrder
{
    /**
     * Elements HTML never closes, which a template may write without "/>".
     * Matched as written: `<Link>` is a component that closes, not `<link>`.
     */
    protected const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    /**
     * Move the element whose "<" is at one offset to just before or after
     * the element at another, keeping each on its own line when it was.
     *
     * @return array{contents: string, offset: int} The new contents, and where the moved element starts now
     *
     * @throws InvalidArgumentException when the elements are not siblings or the move would break the template.
     */
    public static function move(string $contents, int $offset, int $targetOffset, string $placement): array
    {
        if (! in_array($placement, ['before', 'after'], true)) {
            throw new InvalidArgumentException("Unknown placement [{$placement}].");
        }

        $elements = self::elements($contents);
        $moved = self::find($elements, $offset);
        $target = self::find($elements, $targetOffset);

        if ($moved === $target || $elements[$moved]['parent'] !== $elements[$target]['parent']) {
            throw new InvalidArgumentException('Only an element and its sibling can change places.');
        }

        // An element whose end tag was not found would move without its
        // contents and break the page.
        if (! self::closed($elements[$moved]) || ! self::closed($elements[$target])) {
            throw new InvalidArgumentException('The element\'s end could not be found.');
        }

        $conditional = fn (?int $index) => $index !== null && preg_match('/\sv-else(?:-if)?\b/', $elements[$index]['head']) === 1;

        // A v-else belongs right after its v-if: neither may be pulled away
        // from the other, and nothing may go between them.
        if ($conditional($moved)
            || $conditional(self::next($elements, $moved))
            || ($placement === 'before' && $conditional($target))
            || ($placement === 'after' && $conditional(self::next($elements, $target)))) {
            throw new InvalidArgumentException('The element is shown in turn with another one.');
        }

        [$from, $to] = self::extent($contents, $elements[$moved]);
        $lines = $from !== $elements[$moved]['start'];
        [$targetFrom, $targetTo] = $lines ? self::extent($contents, $elements[$target]) : [$elements[$target]['start'], $elements[$target]['end']];
        $at = $placement === 'before' ? $targetFrom : $targetTo;

        // Moving whole lines needs a whole-line place to go.
        if ($lines && $at !== 0 && $contents[$at - 1] !== "\n") {
            throw new InvalidArgumentException('The element shares its line with another one.');
        }

        $chunk = substr($contents, $from, $to - $from);
        $without = substr($contents, 0, $from).substr($contents, $to);
        $at = $at > $from ? $at - strlen($chunk) : $at;

        return [
            'contents' => substr($without, 0, $at).$chunk.substr($without, $at),
            'offset' => $at + ($elements[$moved]['start'] - $from),
        ];
    }

    /**
     * Put a copy of the element whose "<" is at an offset right after it:
     * on its own lines when it was, or else after it on the same line with
     * the same space before it.
     *
     * @return array{contents: string, offset: int} The new contents, and where the copy starts
     *
     * @throws InvalidArgumentException when the copy would break the template.
     */
    public static function duplicate(string $contents, int $offset): array
    {
        $elements = self::elements($contents);
        $index = self::find($elements, $offset);

        self::guard($elements, $index);

        [$from, $to] = self::extent($contents, $elements[$index]);

        if ($from !== $elements[$index]['start']) {
            $chunk = substr($contents, $from, $to - $from);

            return [
                'contents' => substr($contents, 0, $to).$chunk.substr($contents, $to),
                'offset' => $to + ($elements[$index]['start'] - $from),
            ];
        }

        // On a shared line, the copy keeps the same space before it.
        $space = preg_match('/[ \t]*$/', substr($contents, 0, $from), $match) === 1 ? $match[0] : '';
        $chunk = $space.substr($contents, $from, $to - $from);

        return [
            'contents' => substr($contents, 0, $to).$chunk.substr($contents, $to),
            'offset' => $to + strlen($space),
        ];
    }

    /**
     * Take the element whose "<" is at an offset out of the template, with
     * its own lines when nothing else is on them.
     *
     * @return array{contents: string, offset: int} The new contents, and where the element around it starts
     *
     * @throws InvalidArgumentException when taking it out would break the template.
     */
    public static function remove(string $contents, int $offset): array
    {
        $elements = self::elements($contents);
        $index = self::find($elements, $offset);
        $parent = $elements[$index]['parent'];

        if ($parent === null) {
            throw new InvalidArgumentException('The template itself cannot be taken out.');
        }

        self::guard($elements, $index);

        [$from, $to] = self::extent($contents, $elements[$index]);

        return [
            'contents' => substr($contents, 0, $from).substr($contents, $to),
            'offset' => $elements[$parent]['start'],
        ];
    }

    /**
     * Refuse to copy or take out an element whose extent is not known, or
     * one shown in turn with another: a v-if followed by its v-else, or a
     * v-else, which only works right after its v-if.
     *
     * @param  list<array{start: int, end: int, parent: int|null, head: string}>  $elements
     *
     * @throws InvalidArgumentException
     */
    protected static function guard(array $elements, int $index): void
    {
        if (! self::closed($elements[$index])) {
            throw new InvalidArgumentException('The element\'s end could not be found.');
        }

        $conditional = fn (?int $at) => $at !== null && preg_match('/\sv-else(?:-if)?\b/', $elements[$at]['head']) === 1;

        if ($conditional($index) || $conditional(self::next($elements, $index))) {
            throw new InvalidArgumentException('The element is shown in turn with another one.');
        }
    }

    /**
     * Get the line and column (both from 1) of an offset.
     *
     * @return array{0: int, 1: int}
     */
    public static function position(string $contents, int $offset): array
    {
        $before = substr($contents, 0, $offset);
        $newline = strrpos($before, "\n");

        return [substr_count($before, "\n") + 1, $newline === false ? $offset + 1 : $offset - $newline];
    }

    /**
     * Read every element in the file's top-level template, in order.
     *
     * @return list<array{start: int, end: int, parent: int|null, head: string}> Where each starts and ends, its parent's index, and its start tag
     */
    public static function elements(string $contents): array
    {
        if (preg_match('/^<template\b/m', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        /** @var list<array{tag: string, start: int, end: int, parent: int|null, head: string}> $elements */
        $elements = [];
        /** @var list<int> $open */
        $open = [];
        /** @var array<int, int> $ends Where each closed element's end tag ends */
        $ends = [];
        $position = $match[0][1];
        $length = strlen($contents);

        while ($position < $length) {
            if (preg_match('/<!--|\{\{|<\/?[A-Za-z]/', $contents, $next, PREG_OFFSET_CAPTURE, $position) !== 1) {
                break;
            }

            [$token, $position] = $next[0];

            if ($token === '<!--' || $token === '{{') {
                $close = strpos($contents, $token === '<!--' ? '-->' : '}}', $position);
                $position = $close === false ? $length : $close + ($token === '<!--' ? 3 : 2);

                continue;
            }

            if ($token[1] === '/') {
                preg_match('/\G<\/([A-Za-z][\w.:-]*)\s*>/', $contents, $closing, 0, $position);
                $position += strlen($closing[0] ?? '</');

                // Close the nearest open element with this name, and any
                // left open inside it.
                for ($depth = count($open) - 1; $depth >= 0; $depth--) {
                    if (strcasecmp($elements[$open[$depth]]['tag'], $closing[1] ?? '') === 0) {
                        foreach (array_splice($open, $depth) as $index) {
                            $ends[$index] = $position;
                        }

                        break;
                    }
                }

                if ($open === []) {
                    break;
                }

                continue;
            }

            $element = TemplateElement::atOffset($contents, $position);

            if ($element === null) {
                $position++;

                continue;
            }

            $elements[] = [
                'tag' => $element->tag,
                'start' => $position,
                'end' => $element->end,
                'parent' => $open === [] ? null : $open[count($open) - 1],
                'head' => substr($contents, $position, $element->end - $position),
            ];

            if (! $element->selfClosing($contents) && ! in_array($element->tag, self::VOID, true)) {
                $open[] = array_key_last($elements);
            }

            $position = $element->end;
        }

        return array_map(fn (array $element, int $index) => [
            'start' => $element['start'],
            'end' => $ends[$index] ?? $element['end'],
            'parent' => $element['parent'],
            'head' => $element['head'],
        ], $elements, array_keys($elements));
    }

    /**
     * @param  list<array{start: int, end: int, parent: int|null, head: string}>  $elements
     *
     * @throws InvalidArgumentException when no element starts there.
     */
    protected static function find(array $elements, int $offset): int
    {
        foreach ($elements as $index => $element) {
            if ($element['start'] === $offset) {
                return $index;
            }
        }

        throw new InvalidArgumentException("No element starts at [{$offset}].");
    }

    /**
     * Get the sibling right after an element, if any.
     *
     * @param  list<array{start: int, end: int, parent: int|null, head: string}>  $elements
     */
    protected static function next(array $elements, int $index): ?int
    {
        $parent = $elements[$index]['parent'];

        for ($next = $index + 1; $next < count($elements); $next++) {
            if ($elements[$next]['parent'] === $parent) {
                return $next;
            }

            if ($parent !== null && $elements[$next]['start'] >= $elements[$parent]['end']) {
                break;
            }
        }

        return null;
    }

    /**
     * Determine whether the element's whole extent is known: its end tag was
     * found, or it has none.
     *
     * @param  array{start: int, end: int, parent: int|null, head: string}  $element
     */
    protected static function closed(array $element): bool
    {
        preg_match('/^<([A-Za-z][\w.:-]*)/', $element['head'], $tag);

        return $element['end'] > $element['start'] + strlen($element['head'])
            || str_ends_with($element['head'], '/>')
            || in_array($tag[1] ?? '', self::VOID, true);
    }

    /**
     * Get what moves with an element: its whole lines when nothing else is
     * on them, or else just the element.
     *
     * Comments on their own lines go with the element they belong to: those
     * right above it, and those after it when only the parent's end follows
     * (such as the end marker of a block the element is wrapped in).
     *
     * @param  array{start: int, end: int, parent: int|null, head: string}  $element
     * @return array{0: int, 1: int}
     */
    protected static function extent(string $contents, array $element): array
    {
        $lineStart = strrpos(substr($contents, 0, $element['start']), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $lineEnd = strpos($contents, "\n", $element['end']);
        $lineEnd = $lineEnd === false ? strlen($contents) : $lineEnd + 1;

        $alone = trim(substr($contents, $lineStart, $element['start'] - $lineStart)) === ''
            && trim(substr($contents, $element['end'], $lineEnd - $element['end'])) === '';

        if (! $alone) {
            return [$element['start'], $element['end']];
        }

        $comment = '/^[ \t]*<!--.*-->[ \t]*\n?$/';

        while ($lineStart > 0) {
            $previous = strrpos(substr($contents, 0, $lineStart - 1), "\n");
            $previous = $previous === false ? 0 : $previous + 1;

            if (preg_match($comment, substr($contents, $previous, $lineStart - $previous)) !== 1) {
                break;
            }

            $lineStart = $previous;
        }

        $after = $lineEnd;

        while ($after < strlen($contents)) {
            $next = strpos($contents, "\n", $after);
            $next = $next === false ? strlen($contents) : $next + 1;

            if (preg_match($comment, substr($contents, $after, $next - $after)) !== 1) {
                break;
            }

            $after = $next;
        }

        if ($after > $lineEnd && preg_match('/\G[ \t]*<\//', $contents, $match, 0, $after) === 1) {
            $lineEnd = $after;
        }

        return [$lineStart, $lineEnd];
    }
}
