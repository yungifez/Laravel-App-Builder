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
     * A query or an outside call while the app starts, read from the code
     * only (BoundaryCode): the recorder never sees the app start. It runs
     * for every request, command and queue worker, and before a database
     * may exist at all.
     */
    public const CHANGED_WHILE_BOOTING = 'changed_while_booting';

    /**
     * The phases that must not change anything, and the finding for each.
     */
    protected const PHASES = [
        'authorization' => self::CHANGED_WHILE_AUTHORIZING,
        'validation' => self::CHANGED_WHILE_VALIDATING,
        'rendering' => self::CHANGED_WHILE_RENDERING,
    ];

    /**
     * The findings the owner reads in the proof, and so may accept. The
     * app's start is read from the code only, so it goes to the reviewer.
     */
    public const OWNED = [self::CHANGED_WHILE_AUTHORIZING, self::CHANGED_WHILE_VALIDATING, self::CHANGED_WHILE_RENDERING];

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
     * Add what reading the change's code found (BoundaryCode) to what the
     * recording showed, as "read". A line the recording already holds
     * against the change is said once, as seen. A finding the code had
     * before the change, by what it is rather than by line, only moved:
     * it is counted as existing and not held against the change, unless
     * the change added more of the same. Null when neither found anything
     * to say.
     *
     * @param  array{phased: int, unknown: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>}|null  $measured  From measure()
     * @param  array{read: list<array{kind: string, what: string, at: string, in: string}>, before: list<array{kind: string, what: string, at: string, in: string}>}  $code  From BoundaryCode::inPatch()
     * @param  list<string>|null  $phases  The phases to check, all when null; the app's start is always read
     * @return array{phased: int, unknown: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>, read: list<array{kind: string, what: string, at: string, in: string}>}|null
     */
    public static function withRead(?array $measured, array $code, ?array $phases = null): ?array
    {
        $before = array_count_values(array_map(BoundaryCode::identity(...), $code['before']));
        $moved = 0;

        $findings = self::unmoved($measured['findings'] ?? [], $before, $moved);
        $seen = array_column($measured['findings'] ?? [], 'at');
        $checked = [...array_values(array_intersect_key(self::PHASES, array_flip($phases ?? array_keys(self::PHASES)))), self::CHANGED_WHILE_BOOTING];
        $read = array_values(array_filter($code['read'], fn (array $finding) => in_array($finding['kind'], $checked, true) && ! in_array($finding['at'], $seen, true)));
        $read = self::unmoved($read, $before, $moved);

        if ($measured === null && $read === [] && $moved === 0) {
            return null;
        }

        $measured ??= ['phased' => 0, 'unknown' => 0, 'existing' => 0, 'findings' => []];

        return [
            ...$measured,
            'existing' => $measured['existing'] + $moved,
            'findings' => $findings,
            'read' => array_slice($read, 0, self::KEPT),
        ];
    }

    /**
     * Set aside the findings a person said the change makes on purpose, by
     * what they are (BoundaryCode::identity()), seen or read. They are
     * counted as "accepted", not held against the change.
     *
     * @param  array{phased: int, unknown: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>, read?: list<array{kind: string, what: string, at: string, in: string}>, accepted?: int}|null  $measured  From measure() or withRead()
     * @param  list<string>  $accepted  The identities accepted
     * @return array{phased: int, unknown: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, in: string|null, test: string|null}>, read?: list<array{kind: string, what: string, at: string, in: string}>, accepted?: int}|null
     */
    public static function without(?array $measured, array $accepted): ?array
    {
        if ($measured === null || $accepted === []) {
            return $measured;
        }

        $set = 0;
        $findings = [];
        $read = [];

        foreach ($measured['findings'] as $finding) {
            if (in_array(BoundaryCode::identity($finding), $accepted, true)) {
                $set++;
            } else {
                $findings[] = $finding;
            }
        }

        foreach ($measured['read'] ?? [] as $finding) {
            if (in_array(BoundaryCode::identity($finding), $accepted, true)) {
                $set++;
            } else {
                $read[] = $finding;
            }
        }

        return [
            ...$measured,
            'findings' => $findings,
            ...(isset($measured['read']) ? ['read' => $read] : []),
            'accepted' => ($measured['accepted'] ?? 0) + $set,
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
     * Keep what the change has more of than the code had before it. A
     * finding the code already had uses up one of those and only moved.
     *
     * @template TFinding of array{kind: string, what: string, in: string|null}
     *
     * @param  list<TFinding>  $findings
     * @param  array<string, int>  $before  How many of each finding the code had, by identity
     * @return list<TFinding>
     */
    protected static function unmoved(array $findings, array &$before, int &$moved): array
    {
        $kept = [];

        foreach ($findings as $finding) {
            $identity = BoundaryCode::identity($finding);

            if (($before[$identity] ?? 0) > 0) {
                $before[$identity]--;
                $moved++;
            } else {
                $kept[] = $finding;
            }
        }

        return $kept;
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
