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
     * A new address that changes something with no check on who may use
     * it (§12: a new mutating surface needs authentication).
     */
    public const OPEN_TO_ANYONE = 'open_to_anyone';

    /**
     * An address that lost a check on who may use it.
     */
    public const NO_LONGER_CHECKED = 'no_longer_checked';

    /**
     * The kinds the owner may say they want, such as a contact form anyone
     * can send.
     */
    public const OWNED = [self::OPEN_TO_ANYONE, self::NO_LONGER_CHECKED];

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
     * Get the findings about who may use the change's addresses: each new
     * one that changes something with no check, and each one that lost a
     * check. Only Laravel's own checks are read, so an app that guards
     * with its own middleware is asked too, and the owner's yes keeps it.
     *
     * @param  array{added?: list<array{route: string, middleware: list<string>}>, changed?: list<array{route: string, lost: list<string>, gained: list<string>}>}|null  $changes
     * @param  list<string>  $accepted  Identities the owner said they want
     * @return list<array{kind: string, route: string}>
     */
    public static function findings(?array $changes, array $accepted = []): array
    {
        $findings = [
            ...array_map(fn (string $route) => ['kind' => self::OPEN_TO_ANYONE, 'route' => $route], self::unguarded($changes)),
            ...array_map(fn (array $route) => ['kind' => self::NO_LONGER_CHECKED, 'route' => $route['route']], self::opened($changes)),
        ];

        return array_values(array_filter($findings, fn (array $finding) => ! in_array(self::identity($finding), $accepted, true)));
    }

    /**
     * Name a finding the same way each time the checks run.
     *
     * @param  array{kind: string, route: string}  $finding
     */
    public static function identity(array $finding): string
    {
        return "{$finding['kind']}|{$finding['route']}";
    }

    /**
     * Say what a finding is, for the agent that sends the change back.
     *
     * @param  array{kind: string, route: string}  $finding
     */
    public static function finding(array $finding): string
    {
        return $finding['kind'] === self::OPEN_TO_ANYONE
            ? __('The new route :route changes data, but nothing checks who may use it. Put it behind the auth middleware with a policy or a form request that authorizes, as the app does for its other routes. If anyone must be able to use it, such as a contact form, ask the owner to keep it.', ['route' => $finding['route']])
            : __('The route :route no longer checks who may use it. Put the check back. If the request asks for exactly this, ask the owner to keep it.', ['route' => $finding['route']]);
    }

    /**
     * Get a route's address as the owner sees it in the browser. A
     * Livewire request goes to Livewire's own address, which the owner
     * never sees, so it is named by its component instead: the recorder
     * writes "/livewire-…/update#send-receipt@send", with "+" between
     * components when a request updates several.
     */
    public static function address(string $route): string
    {
        $work = self::work($route);

        if ($work !== null) {
            return __('the work “:name”', ['name' => $work]);
        }

        $address = Str::after($route, ' ');

        if (! self::isPart($address)) {
            return $address;
        }

        // The first component, as "send-receipt", "pages::cart", "orders.list" or a class name.
        $name = Str::of($address)->after('#')->before('+')->before('@')->afterLast('\\')->afterLast('::')->afterLast('.')->snake()->replace(['-', '_'], ' ')->squish()->value();

        return ! str_contains($address, '#') || $name === '' ? __('a part of a page') : __('the :name part of a page', ['name' => $name]);
    }

    /**
     * Whether an address is one a part of a page uses to update itself,
     * which the owner never sees in the browser.
     */
    public static function isPart(string $address): bool
    {
        return str_starts_with($address, '/livewire-');
    }

    /**
     * Get the name of work the app does on its own, with no person there
     * and so no address: a command the schedule runs, or a job on a queue.
     * The recorder writes "ARTISAN reminders:send" and
     * "JOB App\Jobs\SendReminder". Null for a route a person uses.
     */
    public static function work(string $route): ?string
    {
        $name = AppTraces::command($route) ?? AppTraces::job($route);

        return $name === null ? null : Str::of($name)->afterLast('\\')->snake()->replace([':', '-', '_', '.'], ' ')->squish()->value();
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
