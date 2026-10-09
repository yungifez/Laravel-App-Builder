<?php

namespace TraceRecorder;

use Closure;
use ReflectionFunction;
use Throwable;

/**
 * Names a Livewire request. All components of an app share one route for
 * what a person does on them, and a test renders a component at an address
 * with a random part. So the component, and what the request calls on it,
 * goes after the route: each place has its own name, the same in every run.
 */
class Wired
{
    /**
     * The start of the address a test renders a component at.
     */
    protected const RENDERS = '/livewire-unit-test-endpoint';

    /**
     * How many components, and calls on one component, a name holds.
     */
    protected const PARTS = 3;

    /**
     * Get the route with the components of the request after it. A route
     * that is not for a component stays as it is.
     */
    public static function route($request, $route, string $uri, $response = null): string
    {
        $renders = str_starts_with($uri, self::RENDERS.'/');

        try {
            if ($renders) {
                $component = self::rendered($response) ?? self::asked($route);

                return self::RENDERS.($component === null ? '' : '#'.$component);
            }

            $components = self::called($request);

            return $components === [] ? $uri : $uri.'#'.implode('+', self::some($components));
        } catch (Throwable) {
            return $renders ? self::RENDERS : $uri;
        }
    }

    /**
     * Get the components the request is for, each with the methods it calls.
     *
     * @return list<string>
     */
    protected static function called($request): array
    {
        if (! $request->isMethod('POST') || ! $request->headers->has('X-Livewire')) {
            return [];
        }

        $components = [];

        foreach ((array) $request->input('components') as $component) {
            $name = self::name(is_array($component) ? ($component['snapshot'] ?? null) : null);

            if ($name === null) {
                return [];
            }

            $calls = [];

            foreach ((array) ($component['calls'] ?? []) as $call) {
                $method = is_array($call) ? ($call['method'] ?? null) : null;

                if (is_string($method) && preg_match('/^[\w$]{1,60}$/', $method) === 1) {
                    $calls[$method] = $method;
                }
            }

            $components[] = $name.($calls === [] ? '' : '@'.implode(',', self::some(array_values($calls))));
        }

        return $components;
    }

    /**
     * Get the component the response rendered first: the one the test asked for.
     */
    protected static function rendered($response): ?string
    {
        $content = is_object($response) && method_exists($response, 'getContent') ? $response->getContent() : null;

        if (! is_string($content) || preg_match('/\swire:snapshot="([^"]*)"/', $content, $found) !== 1) {
            return null;
        }

        return self::name(html_entity_decode($found[1], ENT_QUOTES));
    }

    /**
     * Get the component the test asked the route to render.
     */
    protected static function asked($route): ?string
    {
        $uses = $route->getAction('uses');
        $name = $uses instanceof Closure ? ((new ReflectionFunction($uses))->getStaticVariables()['name'] ?? null) : null;

        return self::valid(is_object($name) ? $name::class : $name);
    }

    /**
     * Get the name of the component a snapshot is of.
     */
    protected static function name(mixed $snapshot): ?string
    {
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;

        return self::valid(is_array($snapshot) && is_array($snapshot['memo'] ?? null) ? ($snapshot['memo']['name'] ?? null) : null);
    }

    /**
     * Get the name when it is one a component can have.
     */
    protected static function valid(mixed $name): ?string
    {
        return is_string($name) && preg_match('/^[\w.:\\\\\/-]{1,120}$/', $name) === 1 ? $name : null;
    }

    /**
     * Keep the first parts, and say that there are more.
     *
     * @param  list<string>  $parts
     * @return list<string>
     */
    protected static function some(array $parts): array
    {
        return count($parts) > self::PARTS ? [...array_slice($parts, 0, self::PARTS), 'more'] : $parts;
    }
}
