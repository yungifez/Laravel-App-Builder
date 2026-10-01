<?php

namespace App\Features;

/**
 * What the app did while its tests used it, request by request, read from
 * the recorder in the box (direction 32). Each request's trace lists its
 * queries, its transactions and what it queued and sent, in order, each
 * with the line of the app's code it came from.
 *
 * Four things are read from the shape of a trace alone, with no idea of
 * what the app is for: a request that only reads saved something, a
 * request the app refused kept part of its work, something was sent while
 * a transaction was still open, and the same lookup ran many times in one
 * request. Only what the change's own lines did counts, so a problem the
 * app already had is never held against a change.
 *
 * A trace cannot say whether any of these was wanted: a page may count its
 * visits. The reviewer holds them against the plan.
 */
class AppTraces
{
    public const SAVED_ON_READ = 'saved_on_read';

    public const KEPT_AFTER_REFUSAL = 'kept_after_refusal';

    public const SENT_BEFORE_SAVED = 'sent_before_saved';

    /**
     * The shortcut a repeated lookup is: the database asked once for each
     * item in a loop, seen while it ran.
     */
    public const REPEAT_RULE = 'SL204';

    protected const WRITES = ['insert', 'update', 'delete', 'replace'];

    protected const SENT = ['job', 'mail', 'notification', 'http'];

    /**
     * The fakes of a test that hide what a request sends. The database
     * keeps the events it started with, so its queries are still seen.
     */
    protected const HIDES = ['events', 'mail', 'queue', 'jobs', 'notifications'];

    /**
     * The most findings kept of each kind, so one change cannot fill the row.
     */
    protected const KEPT = 40;

    /**
     * Read the recorder's file: one request per line. A line that is not a
     * request is left out.
     *
     * @return list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null}>, blind: list<string>, cut: bool}>
     */
    public static function parse(string $report): array
    {
        $requests = [];

        foreach (preg_split('/\R/', trim($report)) ?: [] as $line) {
            $request = json_decode($line, true);

            if (! is_array($request) || ! is_string($request['method'] ?? null) || ! is_array($request['effects'] ?? null)) {
                continue;
            }

            $effects = [];

            foreach ($request['effects'] as $effect) {
                if (! is_array($effect) || ! is_string($effect['kind'] ?? null)) {
                    continue;
                }

                $effects[] = [
                    'kind' => $effect['kind'],
                    'open' => (int) ($effect['open'] ?? 0),
                    ...(is_string($effect['sql'] ?? null) ? ['sql' => $effect['sql']] : []),
                    ...(is_string($effect['what'] ?? null) ? ['what' => $effect['what']] : []),
                    ...(array_key_exists('at', $effect) ? ['at' => is_string($effect['at']) ? $effect['at'] : null] : []),
                ];
            }

            $requests[] = [
                'test' => is_string($request['test'] ?? null) ? $request['test'] : null,
                'method' => $request['method'],
                'route' => is_string($request['route'] ?? null) ? $request['route'] : null,
                'status' => (int) ($request['status'] ?? 0),
                'refused' => (bool) ($request['refused'] ?? false),
                'effects' => $effects,
                'blind' => array_values(array_filter(is_array($request['blind'] ?? null) ? $request['blind'] : [], is_string(...))),
                'cut' => (bool) ($request['cut'] ?? false),
            ];
        }

        return $requests;
    }

    /**
     * Measure the recorded requests against the change: how many ran its
     * new code, what its new code did that the shape of a trace shows to
     * be a problem, and which lookups its lines repeated. Null when
     * nothing was recorded.
     *
     * A thing a request did is the change's when the line it came from is
     * one the patch adds, or when the change added the request's route
     * and the app's own code did it.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null}>, blind: list<string>, cut: bool}>  $requests  From parse()
     * @param  list<string>  $addedRoutes  The routes the change added, as AppRoutes names them
     * @param  int  $repeats  How many times one request must run the same lookup from the same line for it to count
     * @return array{requests: int, reached: int, unseen: int, existing: int, findings: list<array{kind: string, route: string, what: string, at: string|null, test: string|null}>, repeats: list<array{path: string, line: int, count: int, route: string}>}|null
     */
    public static function measure(array $requests, ?string $patch, array $addedRoutes = [], int $repeats = 3): ?array
    {
        if ($requests === []) {
            return null;
        }

        $added = self::addedLines($patch);
        $findings = [];
        $existing = [];
        $repeated = [];
        $reached = 0;
        $unseen = 0;

        foreach ($requests as $request) {
            $route = $request['method'].' '.($request['route'] ?? '?');
            $newRoute = in_array($route, $addedRoutes, true);
            $new = fn (array $effect): bool => is_string($effect['at'] ?? null) && ($newRoute || self::onAddedLine($effect['at'], $added));
            $found = [];

            $ran = array_any($request['effects'], $new);
            $reached += $ran ? 1 : 0;

            // A trace cut short does not say what was put back, so what it
            // saved is not known.
            $kind = match (true) {
                $request['cut'] => null,
                in_array($request['method'], ['GET', 'HEAD'], true) => self::SAVED_ON_READ,
                $request['refused'] => self::KEPT_AFTER_REFUSAL,
                default => null,
            };

            foreach ($kind === null ? [] : self::saved($request['effects']) as $write) {
                $found[] = [$kind, $write];
            }

            $opened = array_any($request['effects'], fn (array $effect) => $effect['kind'] === 'begin');

            // What a fake took was never sent, so nobody saw when the
            // change's code would send it.
            if ($ran && $opened && array_intersect($request['blind'], self::HIDES) !== []) {
                $unseen++;
            }

            foreach ($request['effects'] as $effect) {
                if (in_array($effect['kind'], self::SENT, true) && $effect['open'] > 0) {
                    $found[] = [self::SENT_BEFORE_SAVED, $effect];
                }
            }

            foreach ($found as [$kind, $effect]) {
                $what = isset($effect['sql']) ? self::statement($effect['sql']) : trim($effect['kind'].' '.($effect['what'] ?? ''));
                $key = implode('|', [$kind, $route, $what, $effect['at'] ?? '']);

                if ($new($effect)) {
                    $findings[$key] ??= ['kind' => $kind, 'route' => $route, 'what' => $what, 'at' => $effect['at'] ?? null, 'test' => $request['test']];
                } elseif (is_string($effect['at'] ?? null)) {
                    // What the framework or a package does by itself is not counted at all.
                    $existing[$key] = true;
                }
            }

            foreach (self::lookups($request['effects']) as $at => $count) {
                if ($count >= $repeats && $count > ($repeated[$at]['count'] ?? 0) && self::onAddedLine($at, $added)) {
                    $repeated[$at] = ['count' => $count, 'route' => $route];
                }
            }
        }

        ksort($repeated);

        return [
            'requests' => count($requests),
            'reached' => $reached,
            'unseen' => $unseen,
            'existing' => count($existing),
            'findings' => array_slice(array_values($findings), 0, self::KEPT),
            'repeats' => array_slice(array_map(fn (string $at, array $repeat) => [
                'path' => self::path($at),
                'line' => self::line($at),
                'count' => $repeat['count'],
                'route' => $repeat['route'],
            ], array_keys($repeated), $repeated), 0, self::KEPT),
        ];
    }

    /**
     * Get the findings of one kind.
     *
     * @param  array{findings?: list<array{kind: string, route: string, what: string, at: string|null, test: string|null}>}|null  $measured  From measure()
     * @return list<array{kind: string, route: string, what: string, at: string|null, test: string|null}>
     */
    public static function findings(?array $measured, string $kind): array
    {
        return array_values(array_filter($measured['findings'] ?? [], fn (array $finding) => $finding['kind'] === $kind));
    }

    /**
     * Add the lookups a request repeated to the shortcuts found by reading
     * the code, each once.
     *
     * @param  list<array{rule: string, path: string, line: int}>|null  $shortcuts
     * @param  list<array{path: string, line: int, count: int, route: string}>  $repeats
     * @return list<array{rule: string, path: string, line: int}>|null
     */
    public static function withRepeats(?array $shortcuts, array $repeats): ?array
    {
        if ($repeats === []) {
            return $shortcuts;
        }

        $shortcuts ??= [];

        foreach ($repeats as $repeat) {
            if (! array_any($shortcuts, fn (array $shortcut) => $shortcut['path'] === $repeat['path'] && $shortcut['line'] === $repeat['line'])) {
                $shortcuts[] = ['rule' => self::REPEAT_RULE, 'path' => $repeat['path'], 'line' => $repeat['line']];
            }
        }

        return $shortcuts;
    }

    /**
     * Get the writes of a request that stayed: those not inside a
     * transaction of its own that was rolled back.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null}>  $effects
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null}>
     */
    protected static function saved(array $effects): array
    {
        $levels = [[]];

        foreach ($effects as $effect) {
            $inner = count($levels) - 1;

            if ($effect['kind'] === 'begin') {
                $levels[] = [];
            } elseif ($effect['kind'] === 'commit' && $inner > 0) {
                // What an inner transaction saved now waits on the outer one.
                $levels[$inner - 1] = [...$levels[$inner - 1], ...array_pop($levels)];
            } elseif ($effect['kind'] === 'rollback' && $inner > 0) {
                array_pop($levels);
            } elseif ($effect['kind'] === 'query' && in_array(self::verb($effect['sql'] ?? ''), self::WRITES, true)) {
                $levels[$inner][] = $effect;
            }
        }

        $saved = [];

        // A transaction still open at the end is the request's to commit.
        foreach ($levels as $level) {
            foreach ($level as $write) {
                $saved[] = $write;
            }
        }

        return $saved;
    }

    /**
     * Count the lookups a request ran more than once, by the line of the
     * app's code they came from. The same lookup is the same query from
     * the same line.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null}>  $effects
     * @return array<string, int>
     */
    protected static function lookups(array $effects): array
    {
        $counts = [];

        foreach ($effects as $effect) {
            $at = $effect['at'] ?? null;
            $sql = $effect['sql'] ?? '';

            if ($effect['kind'] === 'query' && is_string($at) && self::verb($sql) === 'select') {
                $counts[$at][$sql] = ($counts[$at][$sql] ?? 0) + 1;
            }
        }

        $lines = [];

        foreach ($counts as $at => $queries) {
            $lines[$at] = max($queries);
        }

        return $lines;
    }

    /**
     * Say what a query does in two words, such as "update users".
     */
    protected static function statement(string $sql): string
    {
        return trim(self::verb($sql).' '.(preg_match('/\b(?:into|update|from)\s+[`"\[]?([\w.]+)/i', $sql, $table) === 1 ? $table[1] : ''));
    }

    /**
     * Get the first word of a query, in lower case.
     */
    protected static function verb(string $sql): string
    {
        return strtolower((string) strtok(ltrim($sql, " \t\n\r("), " \t\n\r"));
    }

    /**
     * Get the lines the patch adds, by file.
     *
     * @return array<string, list<int>>
     */
    protected static function addedLines(?string $patch): array
    {
        $added = [];

        foreach (PatchSummary::files($patch) as $file) {
            $added[$file['path']] = array_column(PatchSummary::addedLines($file['diff']), 'line');
        }

        return $added;
    }

    /**
     * Determine if a place in the code ("path:line") is a line the patch adds.
     *
     * @param  array<string, list<int>>  $added
     */
    protected static function onAddedLine(string $at, array $added): bool
    {
        return in_array(self::line($at), $added[self::path($at)] ?? [], true);
    }

    protected static function path(string $at): string
    {
        return (string) preg_replace('/:\d+$/', '', $at);
    }

    protected static function line(string $at): int
    {
        return (int) substr((string) strrchr($at, ':'), 1);
    }
}
