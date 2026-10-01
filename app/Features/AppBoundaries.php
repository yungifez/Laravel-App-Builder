<?php

namespace App\Features;

/**
 * What the change's code did in a part of a request where Laravel expects
 * nothing to change (direction 33). Laravel runs code in phases: it checks
 * whether the person may act, checks what they sent, handles the request
 * and builds the answer. The recorder says which phase each thing a
 * request did ran in, from the framework's own frames, so the rule does
 * not depend on where the app keeps its code: a policy method that saves
 * is only a problem when it runs as a check.
 *
 * Three phases must not change anything. A check of who may act runs
 * many times per page, for example once per row. A check of what was
 * sent runs before the app decides to act, so what it saves or sends
 * stays when the request is refused later. Building the answer runs once
 * per view, resource or prop, often more than once per request.
 *
 * Each finding was seen to happen, so it is proven, but only on the paths
 * the tests take. Only what the change's own lines did counts, as in
 * AppTraces. A phase the recorder could not name is never guessed at.
 */
class AppBoundaries
{
    public const CHANGED_WHILE_AUTHORIZING = 'changed_while_authorizing';

    public const CHANGED_WHILE_VALIDATING = 'changed_while_validating';

    public const CHANGED_WHILE_RENDERING = 'changed_while_rendering';

    /**
     * The phases that must not change anything, and the finding for each.
     */
    protected const PHASES = [
        'authorization' => self::CHANGED_WHILE_AUTHORIZING,
        'validation' => self::CHANGED_WHILE_VALIDATING,
        'rendering' => self::CHANGED_WHILE_RENDERING,
    ];

    /**
     * What changes the world outside the request, besides a write.
     */
    protected const SENT = ['job', 'mail', 'notification', 'http'];

    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 40;

    /**
     * Find what the change's code saved or sent in a phase that must not
     * change anything. Null when nothing was recorded, or when the
     * recorder did not say the phase of anything.
     *
     * A thing a request did is the change's when the line it came from is
     * one the patch adds, or when the change added the request's route
     * and the app's own code did it.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, effects: list<array{kind: string, sql?: string, what?: string, at?: string|null, phase?: string, frames?: list<string>}>}>  $requests  From AppTraces::parse()
     * @param  list<string>  $addedRoutes  The routes the change added, as AppRoutes names them
     * @param  list<string>|null  $phases  The phases to check, all when null
     * @return array{phased: int, unknown: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>}|null
     */
    public static function measure(array $requests, ?string $patch, array $addedRoutes = [], ?array $phases = null): ?array
    {
        $phased = 0;
        $unknown = 0;

        foreach ($requests as $request) {
            foreach ($request['effects'] as $effect) {
                if (isset($effect['phase'])) {
                    $phased++;
                    $unknown += $effect['phase'] === 'unknown' ? 1 : 0;
                }
            }
        }

        // A recorder that names no phase says nothing about these rules.
        if ($phased === 0) {
            return null;
        }

        $added = AppTraces::addedLines($patch);
        $checked = array_intersect_key(self::PHASES, array_flip($phases ?? array_keys(self::PHASES)));
        $findings = [];
        $existing = [];

        foreach ($requests as $request) {
            $route = $request['method'].' '.($request['route'] ?? '?');
            $newRoute = in_array($route, $addedRoutes, true);

            foreach ($request['effects'] as $effect) {
                $kind = $checked[$effect['phase'] ?? ''] ?? null;
                $at = $effect['at'] ?? null;

                // What the framework or a package does by itself is not counted at all.
                if ($kind === null || ! is_string($at) || ! self::changes($effect)) {
                    continue;
                }

                $what = isset($effect['sql']) ? AppTraces::statement($effect['sql']) : trim($effect['kind'].' '.($effect['what'] ?? ''));
                $key = implode('|', [$kind, $route, $what, $at]);

                if ($newRoute || AppTraces::onAddedLine($at, $added)) {
                    $findings[$key] ??= ['kind' => $kind, 'route' => $route, 'what' => $what, 'at' => $at, 'in' => $effect['frames'][0] ?? null, 'test' => $request['test']];
                } else {
                    $existing[$key] = true;
                }
            }
        }

        return [
            'phased' => $phased,
            'unknown' => $unknown,
            'existing' => count($existing),
            'findings' => array_slice(array_values($findings), 0, self::KEPT),
        ];
    }

    /**
     * Get the findings of one kind.
     *
     * @param  array{findings?: list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>}|null  $measured  From measure()
     * @return list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>
     */
    public static function findings(?array $measured, string $kind): array
    {
        return array_values(array_filter($measured['findings'] ?? [], fn (array $finding) => $finding['kind'] === $kind));
    }

    /**
     * Determine if a thing a request did changes something: a write, or
     * something queued or sent out of the app.
     *
     * @param  array{kind: string, sql?: string}  $effect
     */
    protected static function changes(array $effect): bool
    {
        return AppTraces::writes($effect) || in_array($effect['kind'], self::SENT, true);
    }
}
