<?php

namespace App\Features;

/**
 * What the app leaves behind when one thing it depends on fails while a
 * request runs (direction 32). The recorder in the box makes one thing
 * fail in one request of one test: an email that cannot be sent, an
 * outside service that does not answer, or a save the database refuses.
 * The trace of that request then shows what stayed.
 *
 * Nothing is random. The places a failure is caused come from the trace of
 * the tests' normal run, so the same change gives the same places. Only
 * requests that ran the change's code are used, and a finding is the
 * change's only when one of its own lines sent, saved or failed.
 *
 * Three things are read from what stayed: the app saved, then the person
 * got an error; the app sent something, then lost what it was saving; and
 * the app lost one part of what it was saving but kept another. The last
 * one is how a request that saves in steps, with no transaction around
 * them, is found. What a job on the queue does is left out: in use that
 * job runs later, by itself.
 */
class AppFaults
{
    public const SAVED_THEN_FAILED = 'saved_then_failed';

    public const SENT_THEN_LOST = 'sent_then_lost';

    public const SAVED_IN_PART = 'saved_in_part';

    public const SEND = 'send';

    public const SAVE = 'save';

    /**
     * What the recorder can make fail when the app sends it.
     */
    protected const FAILS = ['mail', 'http'];

    /**
     * What has left the app once it happened. A notification is not
     * counted: one kept in the database is a save, and one sent by email
     * is seen as the email.
     */
    protected const SENT = ['job', 'mail', 'http'];

    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 40;

    /**
     * Find the places where a failure can be caused: each email and outside
     * call a request makes, the last save of each transaction a request
     * commits, and the last save the app's code makes outside a transaction
     * once the request has saved or sent something. Only requests that ran
     * the change's code are used. Places on the change's own lines come
     * first, so a small budget goes to them.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool}>, blind: list<string>, cut: bool, n?: int, fault?: int}>  $requests  From AppTraces::parse(), of the tests' normal run
     * @return list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string}, own: bool}>
     */
    public static function points(array $requests, ?string $patch): array
    {
        $added = AppTraces::addedLines($patch);
        $new = fn (array $effect): bool => is_string($effect['at'] ?? null) && AppTraces::onAddedLine($effect['at'], $added);
        $points = [];

        foreach ($requests as $request) {
            $filter = self::filter($request['test']);

            if ($request['test'] === null || $filter === null || ! isset($request['n']) || $request['cut'] || ! array_any($request['effects'], $new)) {
                continue;
            }

            $route = $request['method'].' '.($request['route'] ?? '?');
            $stayed = AppTraces::saved($request['effects']);
            $found = [];
            $last = null;
            $apart = null;
            $done = false;

            foreach ($request['effects'] as $place => $effect) {
                if ($effect['job'] ?? false) {
                    continue;
                }

                if (in_array($effect['kind'], self::FAILS, true)) {
                    $found[] = [self::SEND, $place, $effect];
                } elseif ($effect['open'] > 0 && AppTraces::writes($effect)) {
                    $last = [self::SAVE, $place, $effect];
                } elseif ($effect['kind'] === 'commit' && $last !== null) {
                    // The last save: everything before it in the transaction
                    // was done, and must be undone with it.
                    $found[] = $last;
                    $last = null;
                } elseif ($done && isset($stayed[$place]) && is_string($effect['at'] ?? null)) {
                    // A save in steps: nothing undoes the steps before it.
                    $apart = [self::SAVE, $place, $effect];
                }

                $done = $done || in_array($effect['kind'], self::SENT, true) || (isset($stayed[$place]) && is_string($effect['at'] ?? null));
            }

            foreach ([...$found, ...($apart === null ? [] : [$apart])] as [$fails, $at, $failed]) {
                $points[implode('|', [$fails, $route, self::name($failed), $failed['at'] ?? ''])] ??= [
                    'fails' => $fails,
                    'route' => $route,
                    'failed' => self::name($failed),
                    'at' => $failed['at'] ?? null,
                    'test' => $request['test'],
                    'filter' => $filter,
                    'fault' => ['test' => $request['test'], 'request' => $request['n'], 'effect' => $at, 'kind' => $failed['kind']],
                    'own' => $new($failed),
                ];
            }
        }

        $points = array_values($points);

        return [...array_filter($points, fn (array $point) => $point['own']), ...array_filter($points, fn (array $point) => ! $point['own'])];
    }

    /**
     * Read what the app left behind at each place a failure was caused.
     * Null when there was no place to cause one.
     *
     * A place counts as missed when its failure did not happen: the test
     * took another way this time, or could not run. Nothing is known about
     * a missed place, or about a place that was not tried.
     *
     * @param  list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string}, own: bool}>  $points  From points()
     * @param  array<int, list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool}>, blind: list<string>, cut: bool, n?: int, fault?: int}>>  $runs  What was recorded when each place's failure was caused, by the place's position in $points; a place not tried is absent
     * @return array{points: int, run: int, missed: int, existing: int, findings: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string}>}|null
     */
    public static function measure(array $points, array $runs, ?string $patch): ?array
    {
        if ($points === []) {
            return null;
        }

        $added = AppTraces::addedLines($patch);
        $findings = [];
        $existing = [];
        $run = 0;

        foreach ($runs as $position => $requests) {
            $point = $points[$position] ?? null;
            $hit = $point === null ? null : array_find($requests, fn (array $request) => $request['test'] === $point['test']
                && ($request['n'] ?? null) === $point['fault']['request']
                && ($request['fault'] ?? null) === $point['fault']['effect']);

            if ($point === null || $hit === null) {
                continue;
            }

            $run++;

            foreach (self::left($point['fails'], $hit) as $kind => $effects) {
                $key = implode('|', [$kind, $point['route'], $point['failed'], $point['at'] ?? '']);
                $own = $point['own'] || array_any($effects, fn (array $effect) => is_string($effect['at'] ?? null) && AppTraces::onAddedLine($effect['at'], $added));

                if (! $own) {
                    $existing[$key] = true;

                    continue;
                }

                $findings[$key] ??= [
                    'kind' => $kind,
                    'route' => $point['route'],
                    'failed' => $point['failed'],
                    'what' => implode(', ', array_slice(array_unique(array_map(self::name(...), $effects)), 0, 6)),
                    'at' => $point['at'],
                    'test' => $point['test'],
                ];
            }
        }

        return [
            'points' => count($points),
            'run' => $run,
            'missed' => count($runs) - $run,
            'existing' => count($existing),
            'findings' => array_slice(array_values($findings), 0, self::KEPT),
        ];
    }

    /**
     * Get the findings of one kind.
     *
     * @param  array{findings?: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string}>}|null  $measured  From measure()
     * @return list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string}>
     */
    public static function findings(?array $measured, string $kind): array
    {
        return array_values(array_filter($measured['findings'] ?? [], fn (array $finding) => $finding['kind'] === $kind));
    }

    /**
     * Get what a request left behind after its failure that the failure
     * should have stopped or undone, by the kind of problem it is.
     *
     * @param  array{status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool}>, fault?: int}  $hit  The request the failure was caused in
     * @return array<string, list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool}>>
     */
    protected static function left(string $fails, array $hit): array
    {
        $place = $hit['fault'] ?? 0;
        $before = fn (array $effect, int $at): bool => $at < $place && ! ($effect['job'] ?? false);
        $stayed = AppTraces::saved($hit['effects']);
        // What the framework or a package saved by itself is not part of
        // what the app was saving.
        $kept = array_values(array_filter($stayed, fn (array $effect, int $at) => $before($effect, $at) && is_string($effect['at'] ?? null), ARRAY_FILTER_USE_BOTH));

        if ($fails === self::SEND) {
            // The person got an error page, but what the request saved
            // before the failure is still there.
            return $hit['status'] >= 500 && $kept !== [] ? [self::SAVED_THEN_FAILED => $kept] : [];
        }

        $failed = $hit['effects'][$place] ?? null;

        // The save was refused before it ran. In a transaction, the app
        // lost it when the transaction was put back; an app that carried
        // on to commit took the failure in, and what it did then is not
        // known from a trace. Outside a transaction the same holds when
        // the request did not end in a server error. A later try that
        // saved the same thing lost nothing.
        $again = fn (array $effect, int $at): bool => $at > $place && ($effect['sql'] ?? null) === ($failed['sql'] ?? '') && ($effect['at'] ?? null) === ($failed['at'] ?? null);
        $tookIn = $failed !== null && ($failed['open'] > 0 ? isset($stayed[$place]) : $hit['status'] < 500);

        if ($failed === null || $tookIn || array_any($stayed, $again)) {
            return [];
        }

        $sent = array_values(array_filter($hit['effects'], fn (array $effect, int $at) => $before($effect, $at) && in_array($effect['kind'], self::SENT, true), ARRAY_FILTER_USE_BOTH));

        return array_filter([self::SENT_THEN_LOST => $sent, self::SAVED_IN_PART => $kept]);
    }

    /**
     * Name a thing a request did in a few words, such as "insert orders"
     * or "mail App\Mail\OrderPlaced".
     *
     * @param  array{kind: string, sql?: string, what?: string}  $effect
     */
    protected static function name(array $effect): string
    {
        return isset($effect['sql']) ? AppTraces::statement($effect['sql']) : trim($effect['kind'].' '.($effect['what'] ?? ''));
    }

    /**
     * Get what picks the test out of the suite: its method, without the
     * data set it ran with. Null when the name is not one a test runner's
     * filter can take as it is.
     */
    protected static function filter(?string $test): ?string
    {
        $name = (string) preg_replace('/ with data set .*$/s', '', (string) $test);
        $method = substr((string) strrchr($name, ':'), 1);

        return preg_match('/^\w+$/', $method) === 1 ? $method : null;
    }
}
