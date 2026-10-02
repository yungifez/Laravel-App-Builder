<?php

namespace App\Features;

/**
 * Where the app calls each outside service from (direction 33, a
 * containment rule). An app that talks to its payment service only from
 * its billing code keeps one place that knows how: one place to add a
 * retry, an idempotency key or a log. A change that calls the same
 * service from somewhere else starts a second place.
 *
 * Nobody declares these rules yet. They are read from the app itself: a
 * service the rest of the app calls only from code that belongs to some
 * of its areas (the paths in the notes) is kept to those areas. A
 * service it already calls from code no area claims is kept nowhere. The
 * calls come from the recording, so only the paths the tests take are
 * seen, and the reviewer judges each finding against the plan.
 */
class AppContainment
{
    /**
     * A call to an outside service from outside the areas that call it.
     */
    public const CALLED_ELSEWHERE = 'called_from_elsewhere';

    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 20;

    /**
     * Find calls the change's own lines make to an outside service from
     * outside the areas the rest of the app calls it from. Null when the
     * recording shows no service the app keeps to its areas.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, effects: list<array{kind: string, what?: string, at?: string|null, frames?: list<string>}>}>  $requests  From AppTraces::parse()
     * @param  callable(string): list<string>  $areas  The names of the areas a file of the app belongs to
     * @return array{services: int, findings: list<array{route: string, what: string, at: string, in: string|null, from: list<string>, home: list<string>, test: string|null}>}|null
     */
    public static function measure(array $requests, ?string $patch, callable $areas): ?array
    {
        $added = AppTraces::addedLines($patch);
        $homes = [];
        $loose = [];
        $new = [];

        foreach ($requests as $request) {
            foreach ($request['effects'] as $effect) {
                $at = $effect['at'] ?? null;

                if ($effect['kind'] !== 'http' || ! is_string($at)) {
                    continue;
                }

                $host = self::host($effect['what'] ?? '');
                $from = $areas((string) preg_replace('/:\d+$/', '', $at));

                if (AppTraces::onAddedLine($at, $added)) {
                    $new[] = ['host' => $host, 'from' => $from, 'request' => $request, 'effect' => $effect, 'at' => $at];
                } elseif ($from === []) {
                    $loose[$host] = true;
                } else {
                    $homes[$host] = array_values(array_unique([...$homes[$host] ?? [], ...$from]));
                }
            }
        }

        $homes = array_diff_key($homes, $loose);

        if ($homes === []) {
            return null;
        }

        $findings = [];

        foreach ($new as $call) {
            $home = $homes[$call['host']] ?? null;

            if ($home === null || array_intersect($call['from'], $home) !== []) {
                continue;
            }

            $findings[$call['at'].'|'.$call['host']] ??= [
                'route' => $call['request']['method'].' '.($call['request']['route'] ?? '?'),
                'what' => trim('http '.($call['effect']['what'] ?? '')),
                'at' => $call['at'],
                'in' => $call['effect']['frames'][0] ?? null,
                'from' => $call['from'],
                'home' => $home,
                'test' => $call['request']['test'],
            ];
        }

        return ['services' => count($homes), 'findings' => array_slice(array_values($findings), 0, self::KEPT)];
    }

    /**
     * Name a finding by what it is, not by its line: the service and the
     * method that calls it.
     *
     * @param  array{what: string, at: string, in: string|null}  $finding
     */
    public static function identity(array $finding): string
    {
        return implode('|', [self::CALLED_ELSEWHERE, $finding['in'] ?? preg_replace('/:\d+$/', '', $finding['at']), self::host($finding['what'])]);
    }

    /**
     * Say what a finding is, and how to fix it, for the coder, in a part
     * the owner asked to be extra careful with. Elsewhere the plan may ask
     * for a second place, so it stays with the reviewer.
     *
     * @param  array{route: string, what: string, at: string, in: string|null, from: list<string>, home: list<string>, test: string|null}  $finding
     */
    public static function finding(array $finding): string
    {
        return __(':route: :what at :at:in. The rest of the app calls this service only from :home. The owner asked to be extra careful with this part of the app, so it holds the change back. Call it through the code that already does, so one place knows how to talk to it.', [
            'route' => $finding['route'],
            'what' => $finding['what'],
            'at' => $finding['at'],
            'in' => $finding['in'] === null ? '' : " in {$finding['in']}",
            'home' => implode(', ', $finding['home']),
        ]);
    }

    /**
     * Get the service a recorded call went to, as "POST api.stripe.com"
     * names it.
     */
    protected static function host(string $what): string
    {
        $parts = explode(' ', trim($what));

        return strtolower(end($parts) ?: $what);
    }
}
