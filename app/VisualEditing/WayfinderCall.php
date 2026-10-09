<?php

namespace App\VisualEditing;

/**
 * The server action a part calls through Wayfinder, read from its start tag
 * and the file's imports: a controller method imported from Wayfinder's
 * actions, as in `ProfileController.destroy.form()`, or a named route
 * imported from its routes, as in `store.form()` from `@/routes/login`.
 * Where those are comes from the app's own config (see WayfinderPaths). A
 * button with no call of its own sends the form it is in, so the form's
 * call is its own.
 *
 * Only what the file writes is read. Whether the action still exists is
 * for the app's own route list to say (see FindBehavior).
 */
final readonly class WayfinderCall
{
    /**
     * The helpers Wayfinder gives every action, after its name.
     */
    protected const HELPERS = ['form', 'url', 'get', 'post', 'put', 'patch', 'delete', 'head', 'definition'];

    /**
     * @param  'action'|'route'|'unknown'  $kind  "unknown" when the import looks like Wayfinder's but is not where this app's Wayfinder writes
     * @param  string  $name  A controller and method as "App\Http\Controllers\ProfileController@destroy" (no method when the controller is invokable), or a route name as "login.store"
     * @param  string  $written  The call as the file writes it
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $written,
    ) {}

    /**
     * Find the call the element makes, or the form it is in makes, or null
     * when it makes none through Wayfinder.
     */
    public static function in(string $contents, TemplateElement $element, WayfinderPaths $paths = new WayfinderPaths, string $file = ''): ?self
    {
        $bindings = self::bindings($contents, $paths, $file);

        if ($bindings === []) {
            return null;
        }

        $call = self::call(substr($contents, $element->start, $element->end - $element->start), $bindings);

        if ($call !== null || ! self::sends($contents, $element)) {
            return $call;
        }

        $form = self::form($contents, $element->start);

        return $form === null ? null : self::call(substr($contents, $form->start, $form->end - $form->start), $bindings);
    }

    /**
     * Read the names the file imports from Wayfinder's actions and routes.
     *
     * @return array<string, array{kind: 'action'|'route'|'unknown', base: string, export: string|null}>
     */
    protected static function bindings(string $contents, WayfinderPaths $paths, string $file): array
    {
        $bindings = [];

        preg_match_all('/\bimport\s+(?!type\b)([^;]+?)\s+from\s+[\'"]([^\'"]+)[\'"]/', $contents, $imports, PREG_SET_ORDER);

        foreach ($imports as [, $clause, $source]) {
            $import = $paths->classify($source, $file);

            if ($import === null) {
                continue;
            }

            ['kind' => $kind, 'base' => $base] = $import;

            if (preg_match('/^\s*([A-Za-z_$][\w$]*)\s*(?:,|$)/', $clause, $default) === 1) {
                $bindings[$default[1]] = ['kind' => $kind, 'base' => $base, 'export' => null];
            }

            if (preg_match('/\{([^}]*)\}/', $clause, $named) === 1) {
                foreach (explode(',', $named[1]) as $name) {
                    if (preg_match('/^\s*(?:type\s+)?([A-Za-z_$][\w$]*)(?:\s+as\s+([A-Za-z_$][\w$]*))?\s*$/', $name, $parts) === 1 && ! str_starts_with(trim($name), 'type ')) {
                        $bindings[$parts[2] ?? $parts[1]] = ['kind' => $kind, 'base' => $base, 'export' => $parts[1]];
                    }
                }
            }
        }

        return $bindings;
    }

    /**
     * Find the first call to an imported action in a start tag.
     *
     * @param  array<string, array{kind: 'action'|'route'|'unknown', base: string, export: string|null}>  $bindings
     */
    protected static function call(string $tag, array $bindings): ?self
    {
        $names = implode('|', array_map(fn (string $name) => preg_quote($name, '/'), array_keys($bindings)));

        if (preg_match('/(?<![\w$.])('.$names.')((?:\s*\.\s*[A-Za-z_$][\w$]*)*)\s*\(/', $tag, $match) !== 1) {
            return null;
        }

        $binding = $bindings[$match[1]];
        $members = array_values(array_filter(array_map('trim', explode('.', $match[2]))));

        if ($members !== [] && in_array(end($members), self::HELPERS, true)) {
            array_pop($members);
        }

        $path = [...($binding['export'] === null ? [] : [$binding['export']]), ...$members];

        if ($binding['kind'] === 'unknown') {
            $name = $match[1];
        } elseif ($binding['kind'] === 'action') {
            if (count($path) > 1) {
                return null;
            }

            $name = $binding['base'].($path === [] ? '' : '@'.$path[0]);
        } else {
            $name = implode('.', array_filter([$binding['base'], ...$path], fn (string $part) => $part !== ''));
        }

        return $name === '' ? null : new self($binding['kind'], $name, trim($match[0]).')');
    }

    /**
     * Whether the element sends the form it is in: a button that is not
     * set to do anything else.
     */
    protected static function sends(string $contents, TemplateElement $element): bool
    {
        $tag = substr($contents, $element->start, $element->end - $element->start);

        if (strtolower($element->tag) === 'input') {
            return preg_match('/\btype\s*=\s*["\']submit["\']/i', $tag) === 1;
        }

        return strtolower($element->tag) === 'button' && preg_match('/\btype\s*=\s*["\'](button|reset)["\']/i', $tag) !== 1;
    }

    /**
     * Find the form an offset is inside: the nearest `<form>` or Inertia
     * `<Form>` before it that is not closed before it.
     */
    protected static function form(string $contents, int $offset): ?TemplateElement
    {
        $before = substr($contents, 0, $offset);

        if (preg_match_all('/<(\/?)(form|Form)\b/', $before, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return null;
        }

        $depth = 0;

        foreach (array_reverse($tags) as $tag) {
            if ($tag[1][0] === '/') {
                $depth++;
            } elseif ($depth > 0) {
                $depth--;
            } else {
                return TemplateElement::atOffset($contents, $tag[0][1]);
            }
        }

        return null;
    }
}
