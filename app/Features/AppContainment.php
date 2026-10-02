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
     * Get the service a recorded call went to, as "POST api.stripe.com"
     * names it.
     */
    protected static function host(string $what): string
    {
        $parts = explode(' ', trim($what));

        return strtolower(end($parts) ?: $what);
    }
}
