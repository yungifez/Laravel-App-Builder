<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * The addresses an app answers, as the framework itself lists them
 * (`route:list --json`), compared before and after a change. The list is
 * read from the running framework, so it holds whatever registers a
 * route: route files, packages, attributes or a service provider.
 *
 * It says what a change did to the app's surface, never whether that was
 * wanted: an address that lost its sign-in check may be the request or a
 * mistake. The reviewer holds it against the plan.
 */
class AppRoutes
{
    /**
     * Middleware that decide who may use an address: signed in, verified,
     * allowed, a signed link, a confirmed password. Named by Laravel's own
     * aliases and classes; an app's own middleware is not guessed at.
     */
    protected const GUARDS = '/^(auth(\.basic|\.session)?|verified|can|signed|password\.confirm)(:|$)|\\\\(Authenticate\w*|Authorize|EnsureEmailIsVerified|ValidateSignature|RequirePassword)(:|$)/';

    /**
     * The most routes kept of each kind, so one change cannot fill the row.
     */
    protected const KEPT = 40;

    /**
     * Read the framework's route list into each route's middleware, by
     * method and address, or null when it is not a route list.
     *
     * @return array<string, list<string>>|null
     */
    public static function parse(string $json): ?array
    {
        $data = json_decode(trim($json), true);

        if (! is_array($data) || ! array_is_list($data)) {
            return null;
        }

        $routes = [];

        foreach ($data as $route) {
            if (! is_array($route) || ! is_string($route['method'] ?? null) || ! is_string($route['uri'] ?? null)) {
                return null;
            }

            $address = (is_string($route['domain'] ?? null) ? $route['domain'] : '').'/'.ltrim($route['uri'], '/');
            $middleware = array_values(array_filter(is_array($route['middleware'] ?? null) ? $route['middleware'] : [], is_string(...)));

            // A page answers GET and HEAD alike; one line says both.
            foreach (array_diff(explode('|', $route['method']), ['HEAD']) as $method) {
                $routes["{$method} {$address}"] = $middleware;
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * Compare the routes before a change with those after it: the ones it
     * added, the ones it removed, and the ones whose middleware changed.
     * Null when nothing changed.
     *
     * @param  array<string, list<string>>  $before
     * @param  array<string, list<string>>  $after
     * @return array{added: list<array{route: string, middleware: list<string>}>, removed: list<string>, changed: list<array{route: string, lost: list<string>, gained: list<string>}>}|null
     */
    public static function changes(array $before, array $after): ?array
    {
        $added = [];
        $changed = [];

        foreach ($after as $route => $middleware) {
            if (! isset($before[$route])) {
                $added[] = ['route' => $route, 'middleware' => $middleware];

                continue;
            }

            $lost = array_values(array_diff($before[$route], $middleware));
            $gained = array_values(array_diff($middleware, $before[$route]));

            if ($lost !== [] || $gained !== []) {
                $changed[] = ['route' => $route, 'lost' => $lost, 'gained' => $gained];
            }
        }

        $removed = array_keys(array_diff_key($before, $after));

        if ($added === [] && $removed === [] && $changed === []) {
            return null;
        }

        return [
            'added' => array_slice($added, 0, self::KEPT),
            'removed' => array_slice($removed, 0, self::KEPT),
            'changed' => array_slice($changed, 0, self::KEPT),
        ];
    }

    /**
     * Get the routes that lost a check on who may use them, each with the
     * checks it lost.
     *
     * @param  array{changed?: list<array{route: string, lost: list<string>, gained: list<string>}>}|null  $changes
     * @return list<array{route: string, lost: list<string>}>
     */
    public static function opened(?array $changes): array
    {
        $opened = [];

        foreach ($changes['changed'] ?? [] as $route) {
            $lost = self::guards($route['lost']);

            if ($lost !== []) {
                $opened[] = ['route' => $route['route'], 'lost' => $lost];
            }
        }

        return $opened;
    }

    /**
     * Get the added routes that change something (any method but GET) and
     * have no check on who may use them.
     *
     * @param  array{added?: list<array{route: string, middleware: list<string>}>}|null  $changes
     * @return list<string>
     */
    public static function unguarded(?array $changes): array
    {
        return array_column(array_filter(
            $changes['added'] ?? [],
            fn (array $route) => ! str_starts_with($route['route'], 'GET ') && self::guards($route['middleware']) === [],
        ), 'route');
    }

    /**
     * Get a route's address as the owner sees it in the browser.
     */
    public static function address(string $route): string
    {
        return Str::after($route, ' ');
    }

    /**
     * Get the middleware in the list that decide who may use an address.
     *
     * @param  list<string>  $middleware
     * @return list<string>
     */
    protected static function guards(array $middleware): array
    {
        return array_values(array_filter($middleware, fn (string $name) => preg_match(self::GUARDS, $name) === 1));
    }
}
