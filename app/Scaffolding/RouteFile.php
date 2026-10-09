<?php

namespace App\Scaffolding;

use PhpToken;

/**
 * Add resource routes to an app's own route file, the way the file already
 * writes them (§9, delegation by certainty). A route only signed-in people
 * may use goes inside the file's group for signed-in people; one that
 * anyone may use goes at the end. The file is read as PHP tokens, so a
 * brace in a string or a comment never moves where a route goes.
 *
 * @phpstan-type Reached array{name: string, uri: string, signed_in: bool}
 */
class RouteFile
{
    /**
     * The actions the scaffold writes: the ones that change a record. The
     * screens that show one stay with the coding agent.
     */
    public const ACTIONS = ['store', 'update', 'destroy'];

    /**
     * What a group may also set that would move or rename a route put in
     * it, so such a group is not used.
     */
    protected const MOVES = ['prefix', 'domain', 'name', 'as', 'withoutMiddleware', 'controller'];

    /**
     * Get the URI Laravel's conventions give a model's resource, such as
     * booking-slots for BookingSlot.
     */
    public static function uri(string $model): string
    {
        return str_replace('_', '-', FieldType::table($model));
    }

    /**
     * Find a route the app already has for the URI, by its name or as a
     * resource, so the scaffold never adds a second one.
     *
     * @param  array<string, string>  $files  The app's route files, by path
     */
    public static function taken(array $files, string $uri): ?string
    {
        $quoted = preg_quote($uri, '/');

        foreach ($files as $path => $contents) {
            if (preg_match("/['\"]({$quoted}\\.[a-z_]+)['\"]/", $contents, $match) === 1) {
                return "{$match[1]} in {$path}";
            }

            if (preg_match("/(?:resource|apiResource|resources)\\(\\s*(?:\\[\\s*)?['\"]\\/?{$quoted}['\"]/", $contents) === 1) {
                return "{$uri} in {$path}";
            }
        }

        return null;
    }

    /**
     * Add the resources to the file, or get null when one needs signed-in
     * people and the file has no plain group for them.
     *
     * @param  list<Reached>  $resources
     */
    public static function add(string $contents, array $resources): ?string
    {
        if ($resources === []) {
            return $contents;
        }

        $signedIn = array_values(array_filter($resources, fn (array $resource) => $resource['signed_in']));
        $anyone = array_values(array_filter($resources, fn (array $resource) => ! $resource['signed_in']));
        $fullNames = ! str_contains($contents, 'use App\\Http\\Controllers\\') && str_contains($contents, '\\App\\Http\\Controllers\\');

        if ($signedIn !== []) {
            if (($group = self::group($contents)) === null) {
                return null;
            }

            $lines = self::lines($signedIn, $group['indent'], $fullNames);
            $contents = substr($contents, 0, $group['at']).$lines.substr($contents, $group['at']);
        }

        if ($anyone !== []) {
            $contents = rtrim($contents)."\n\n".self::lines($anyone, '', $fullNames);
        }

        if (! $fullNames) {
            foreach ($resources as $resource) {
                $contents = self::import($contents, "App\\Http\\Controllers\\{$resource['name']}Controller");
            }
        }

        return $contents;
    }

    /**
     * Find the first group of routes behind the auth middleware that adds
     * nothing else to its routes' paths or names, written as
     * Route::middleware('auth')->group(function () { … }) or
     * Route::group(['middleware' => 'auth'], function () { … }).
     *
     * @return array{at: int, indent: string}|null Where its closing brace's line starts, and the indent inside
     */
    protected static function group(string $contents): ?array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($contents), fn (PhpToken $token) => ! $token->isIgnorable()));
        $count = count($tokens);

        for ($i = 0; $i < $count - 2; $i++) {
            if (! ($tokens[$i]->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) && str_ends_with($tokens[$i]->text, 'Route'))
                || ! $tokens[$i + 1]->is(T_DOUBLE_COLON)
                || ! in_array($tokens[$i + 2]->text, ['middleware', 'group'], true)) {
                continue;
            }

            // Read up to the closure's opening brace; a statement that ends
            // first has no closure.
            $auth = $grouped = $moves = false;

            for ($j = $i; $j < $count && ! $tokens[$j]->is(T_FUNCTION); $j++) {
                if ($tokens[$j]->text === ';') {
                    continue 2;
                }

                $text = $tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING) ? substr($tokens[$j]->text, 1, -1) : $tokens[$j]->text;
                $auth = $auth || ($tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING) && ($text === 'auth' || str_starts_with($text, 'auth:')));
                $grouped = $grouped || ($tokens[$j]->is(T_STRING) && $text === 'group');
                $moves = $moves || in_array($text, self::MOVES, true);
            }

            while ($j < $count && $tokens[$j]->text !== '{') {
                $j++;
            }

            if (! $auth || ! $grouped || $moves || $j >= $count) {
                continue;
            }

            $open = $tokens[$j];
            $depth = 0;

            for (; $j < $count; $j++) {
                if (in_array($tokens[$j]->text, ['{', '${'], true) || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($tokens[$j]->text === '}' && --$depth === 0) {
                    break;
                }
            }

            if ($j >= $count) {
                return null;
            }

            $close = $tokens[$j]->pos;
            $at = (int) strrpos(substr($contents, 0, $close), "\n") + 1;

            // A group written on one line has no line of its own to add to.
            if ($at <= $open->pos) {
                continue;
            }

            $closeIndent = substr($contents, $at, $close - $at);
            $first = $tokens[array_search($open, $tokens, true) + 1] ?? null;
            $firstLine = $first === null || $first->pos >= $close ? null : (int) strrpos(substr($contents, 0, $first->pos), "\n") + 1;
            $indent = $firstLine !== null && $firstLine > $open->pos ? substr($contents, $firstLine, $first->pos - $firstLine) : $closeIndent.'    ';

            return ['at' => $at, 'indent' => $indent];
        }

        return null;
    }

    /**
     * @param  list<Reached>  $resources
     */
    protected static function lines(array $resources, string $indent, bool $fullNames): string
    {
        $only = implode(', ', array_map(fn (string $action) => var_export($action, true), self::ACTIONS));

        return implode('', array_map(function (array $resource) use ($indent, $only, $fullNames) {
            $controller = ($fullNames ? '\\App\\Http\\Controllers\\' : '').$resource['name'].'Controller::class';

            return "{$indent}Route::resource(".var_export($resource['uri'], true).", {$controller})->only([{$only}]);\n";
        }, $resources));
    }

    /**
     * Add an import among the file's own, in their order.
     */
    protected static function import(string $contents, string $class): string
    {
        $line = "use {$class};";

        if (str_contains($contents, $line)) {
            return $contents;
        }

        preg_match_all('/^use [^;\n]+;\n/m', $contents, $uses, PREG_OFFSET_CAPTURE);

        foreach ($uses[0] as [$use, $at]) {
            if (strcasecmp(rtrim($use), $line) > 0) {
                return substr($contents, 0, $at)."{$line}\n".substr($contents, $at);
            }
        }

        if ($uses[0] !== []) {
            [$last, $at] = end($uses[0]);

            return substr($contents, 0, $at + strlen($last))."{$line}\n".substr($contents, $at + strlen($last));
        }

        return (string) preg_replace('/^<\?php\s*\n/', "<?php\n\n{$line}\n\n", $contents, 1);
    }
}
