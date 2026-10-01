<?php

namespace App\Features;

/**
 * What the app leaves behind when one thing it depends on fails while a
 * request runs (direction 32). The recorder in the box makes one thing
 * fail in one request of one test: an email that cannot be sent, an
 * outside service that does not answer, a save the database refuses, or a
 * job the queue runs a second time. The trace of that request then shows
 * what stayed.
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
 * them, is found. What a job on the queue does is left out of these
 * three: in use that job runs later, by itself.
 *
 * A fourth thing is read from a job that ran twice: what it sent or added
 * both times. A queue gives a job to a worker at least once, so a job
 * must be safe to run again. The recorder keeps no values, so only the
 * shape is compared: the same send or the same insert from the same line
 * in both runs. A change that is made again (an update, a delete, or an
 * insert that says what to do when the row is there) is not held against
 * the job. A job of the framework that delivers one email, notification
 * or broadcast is not run twice: it has no code of the app to make safe.
 *
 * A fifth thing is read when an outside call gets no answer: the app
 * makes the same call again. A call that got no answer may still have
 * arrived, so the service can do it twice (a payment taken twice). It is
 * a finding for a POST or a PATCH that does not say which call it is
 * (an idempotency key). A GET, a PUT and a DELETE can be made again.
 */
class AppFaults
{
    public const SAVED_THEN_FAILED = 'saved_then_failed';

    public const SENT_THEN_LOST = 'sent_then_lost';

    public const SAVED_IN_PART = 'saved_in_part';

    public const DONE_TWICE = 'done_twice';

    public const CALLED_AGAIN = 'called_again';

    public const SEND = 'send';

    public const SAVE = 'save';

    public const AGAIN = 'again';

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
     * What is tried first when the places are otherwise alike: what cannot
     * be taken back comes before what the database can put back.
     */
    protected const ORDER = ['http' => 0, 'mail' => 0, 'job' => 1, 'query' => 2];

    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 40;

    /**
     * Find the places where a failure can be caused: each email and outside
     * call a request makes, the last save of each transaction a request
     * commits, the last save the app's code makes outside a transaction
     * once the request has saved or sent something, and each job the sync
     * queue ran that sent or added something. Only requests that ran the
     * change's code are used.
     *
     * The places come in the order to try them, so a small budget goes to
     * the ones that tell the most: places on the change's own lines, then
     * places another reading of the change suspects (the same line, or the
     * same route), then what cannot be taken back before what can. The
     * order comes only from the trace, the patch and those findings, so
     * the same change gives the same order.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, blind: list<string>, cut: bool, n?: int, fault?: int}>  $requests  From AppTraces::parse(), of the tests' normal run
     * @param  list<array{route: string, at?: string|null}>  $suspected  Findings of the other engines about the change, such as AppTraces and AppBoundaries give
     * @return list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string}, times: int, own: bool}>
     */
    public static function points(array $requests, ?string $patch, array $suspected = []): array
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
            $jobs = [];
            $last = null;
            $apart = null;
            $done = false;

            foreach ($request['effects'] as $place => $effect) {
                if ($effect['job'] ?? false) {
                    continue;
                }

                // A job of the app that ran here: what it did can be done twice.
                $ran = $effect['kind'] === 'job' && ! ($effect['delivers'] ?? false) ? self::ran($request['effects'], $place) : [];

                if (array_any($ran, fn (array $effect, int $at) => self::repeats($effect, isset($stayed[$at])))) {
                    $jobs[] = [self::AGAIN, $place, $effect, array_any($ran, $new)];
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

            foreach ([...$found, ...($apart === null ? [] : [$apart]), ...$jobs] as $point) {
                [$fails, $at, $failed] = $point;

                $points[implode('|', [$fails, $route, self::name($failed), $failed['at'] ?? ''])] ??= [
                    'fails' => $fails,
                    'route' => $route,
                    'failed' => self::name($failed),
                    'at' => $failed['at'] ?? null,
                    'test' => $request['test'],
                    'filter' => $filter,
                    'fault' => ['test' => $request['test'], 'request' => $request['n'], 'effect' => $at, 'kind' => $failed['kind']],
                    'times' => count(self::same($request['effects'], $failed)),
                    // A job is also the change's when the change wrote what it does.
                    'own' => $new($failed) || ($point[3] ?? false),
                ];
            }
        }

        $routes = array_fill_keys(array_column($suspected, 'route'), true);
        $lines = array_fill_keys(array_filter(array_column($suspected, 'at'), is_string(...)), true);
        $order = fn (array $point): array => [
            $point['own'] ? 0 : 1,
            isset($lines[$point['at'] ?? '']) || isset($routes[$point['route']]) ? 0 : 1,
            self::ORDER[$point['fault']['kind']] ?? count(self::ORDER),
        ];
        $points = array_values($points);

        // Places that are alike stay in the order the trace gave them.
        usort($points, fn (array $one, array $other) => $order($one) <=> $order($other));

        return $points;
    }

    /**
     * Read what the app left behind at each place a failure was caused.
     * Null when there was no place to cause one.
     *
     * A place counts as missed when its failure did not happen: the test
     * took another way this time, or could not run. Nothing is known about
     * a missed place, or about a place that was not tried.
     *
     * @param  list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string}, times: int, own: bool}>  $points  From points()
     * @param  array<int, list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, blind: list<string>, cut: bool, n?: int, fault?: int}>>  $runs  What was recorded when each place's failure was caused, by the place's position in $points; a place not tried is absent
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
                && ($request['fault'] ?? null) === $point['fault']['effect']
                // A second run that is not whole in the trace cannot be compared.
                && ! ($point['fails'] === self::AGAIN && $request['cut']));

            if ($point === null || $hit === null) {
                continue;
            }

            $run++;

            foreach (self::left($point['fails'], $hit, $point['times']) as $kind => $effects) {
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
     * @param  array{status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, fault?: int}  $hit  The request the failure was caused in
     * @param  int  $times  How many times the request did the same thing from the same line in the tests' normal run
     * @return array<string, list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>>
     */
    protected static function left(string $fails, array $hit, int $times): array
    {
        $place = $hit['fault'] ?? 0;

        if ($fails === self::AGAIN) {
            return array_filter([self::DONE_TWICE => self::twice($hit['effects'], $place)]);
        }

        $before = fn (array $effect, int $at): bool => $at < $place && ! ($effect['job'] ?? false);
        $stayed = AppTraces::saved($hit['effects']);
        // What the framework or a package saved by itself is not part of
        // what the app was saving.
        $kept = array_values(array_filter($stayed, fn (array $effect, int $at) => $before($effect, $at) && is_string($effect['at'] ?? null), ARRAY_FILTER_USE_BOTH));

        if ($fails === self::SEND) {
            return array_filter([
                // The person got an error page, but what the request saved
                // before the failure is still there.
                self::SAVED_THEN_FAILED => $hit['status'] >= 500 ? $kept : [],
                self::CALLED_AGAIN => self::calledAgain($hit['effects'], $place, $times),
            ]);
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
     * Get the outside calls a request made again after the same call got
     * no answer. More calls than in the tests' normal run means a new
     * try, and not the next turn of a loop that carried on. A call that
     * says which call it is can be made again.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function calledAgain(array $effects, int $place, int $times): array
    {
        $failed = $effects[$place] ?? null;

        if ($failed === null || preg_match('/^http (POST|PATCH) /', self::name($failed)) !== 1) {
            return [];
        }

        $calls = self::same($effects, $failed);
        $again = array_filter($calls, fn (array $effect, int $at) => $at > $place && ! ($effect['keyed'] ?? false), ARRAY_FILTER_USE_BOTH);

        return count($calls) > $times ? array_slice(array_values($again), 0, 1) : [];
    }

    /**
     * Get the things a request did that are the same as one thing: the
     * same name from the same line. Each keeps its place.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @param  array{kind: string, sql?: string, what?: string, at?: string|null}  $one
     * @return array<int, array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function same(array $effects, array $one): array
    {
        return array_filter($effects, fn (array $effect) => $effect['kind'] === $one['kind'] && self::name($effect) === self::name($one) && ($effect['at'] ?? null) === ($one['at'] ?? null));
    }

    /**
     * Get what a job did in both of its runs that must happen once: the
     * same send, or the same insert that stayed, from the same line.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function twice(array $effects, int $place): array
    {
        $first = self::ran($effects, $place);
        $marker = array_key_last($first) === null ? $place + 1 : array_key_last($first) + 1;

        if (! ($effects[$marker]['again'] ?? false)) {
            return [];
        }

        $stayed = AppTraces::saved($effects);
        $once = [];

        foreach ($first as $at => $effect) {
            if (self::repeats($effect, isset($stayed[$at]))) {
                $once[self::name($effect).'|'.($effect['at'] ?? '')] = true;
            }
        }

        return array_values(array_filter(
            self::ran($effects, $marker),
            fn (array $effect, int $at) => self::repeats($effect, isset($stayed[$at])) && isset($once[self::name($effect).'|'.($effect['at'] ?? '')]),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Get what one run of a job on the sync queue did: the things marked
     * as a job's right after the job, up to where it was made to run
     * again. Each keeps its place among the things the request did.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @return array<int, array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function ran(array $effects, int $place): array
    {
        $ran = [];

        for ($at = $place + 1; ($effects[$at]['job'] ?? false) && ! ($effects[$at]['again'] ?? false); $at++) {
            $ran[$at] = $effects[$at];
        }

        return $ran;
    }

    /**
     * Determine if a thing is done twice when the job that did it runs
     * twice: a send, or an insert that stayed and says nothing about a row
     * that is already there.
     *
     * @param  array{kind: string, sql?: string}  $effect
     */
    protected static function repeats(array $effect, bool $stayed): bool
    {
        $sql = $effect['sql'] ?? '';

        return in_array($effect['kind'], self::SENT, true)
            || ($stayed && AppTraces::verb($sql) === 'insert' && preg_match('/\bon\s+(conflict|duplicate\s+key)\b|^\s*insert\s+(or\s+)?ignore\b|^\s*insert\s+or\s+replace\b/i', $sql) !== 1);
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
