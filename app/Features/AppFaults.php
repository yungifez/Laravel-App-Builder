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
 *
 * A job has a second place: the last save it makes after it sent
 * something. That save is refused, and the job is run again, the way a
 * queue tries a failed job again. A job that asks if it ran before, but
 * marks that only after it sent, passes the run above and sends twice
 * here.
 *
 * One more thing is read with no failure at all. An event can have two
 * or more listeners that Laravel found by itself. Laravel takes those in
 * the order the disk lists their files, so their order is not the same on
 * every machine. The request runs again with them in the reverse order,
 * and it must do the same: the same saves and sends from the same lines,
 * and the same answer. When it does, but two of the listeners use one
 * table and one of them saves to it, the shape cannot say what stayed
 * there, and nothing is said. What a listener changes only in memory is
 * not seen.
 *
 * A job has a third place, also with no failure. Tests run a queued job
 * where it is dispatched. In use it waits on a queue and runs after the
 * response. So the job is held back until the response is made, and the
 * request must do the same without it. The two runs are compared the
 * same way as the two orders of an event.
 */
class AppFaults
{
    public const SAVED_THEN_FAILED = 'saved_then_failed';

    public const SENT_THEN_LOST = 'sent_then_lost';

    public const SAVED_IN_PART = 'saved_in_part';

    public const DONE_TWICE = 'done_twice';

    public const CALLED_AGAIN = 'called_again';

    public const SENT_AGAIN = 'sent_again';

    public const DEPENDS_ON_ORDER = 'depends_on_order';

    public const NEEDS_JOB_DONE = 'needs_job_done';

    public const SEND = 'send';

    public const SAVE = 'save';

    public const AGAIN = 'again';

    public const RETRY = 'retry';

    public const REORDER = 'reorder';

    public const LATER = 'later';

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
    protected const ORDER = ['http' => 0, 'mail' => 0, 'job' => 1, 'later' => 1, 'event' => 1, 'query' => 2];

    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 40;

    /**
     * Find the places where a failure can be caused: each email and outside
     * call a request makes, the last save of each transaction a request
     * commits, the last save the app's code makes outside a transaction
     * once the request has saved or sent something, each job the sync
     * queue ran that sent or added something, each job the request does
     * more after, and each event with found listeners to run in the
     * reverse order. Only requests that ran the change's code are used.
     *
     * The places come in the order to try them, so a small budget goes to
     * the ones that tell the most: places on the change's own lines, then
     * places another reading of the change suspects (the same line, or the
     * same route), then what cannot be taken back before what can. The
     * order comes only from the trace, the patch and those findings, so
     * the same change gives the same order.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>, blind: list<string>, cut: bool, n?: int, fault?: int, events?: list<array{what: string, at: string|null, listeners: list<string>}>}>  $requests  From AppTraces::parse(), of the tests' normal run
     * @param  list<array{route: string, at?: string|null}>  $suspected  Findings of the other engines about the change, such as AppTraces and AppBoundaries give
     * @return list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string, what?: string}, times: int, own: bool, was?: array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool}}>
     */
    public static function points(array $requests, ?string $patch, array $suspected = []): array
    {
        $added = AppTraces::addedLines($patch);
        $new = fn (array $effect): bool => is_string($effect['at'] ?? null) && AppTraces::onAddedLine($effect['at'], $added);
        $points = [];

        foreach ($requests as $request) {
            $filter = self::filter($request['test']);

            // A request ran the change's code when a line of the change did
            // something in it, or dispatched an event its listeners heard.
            $ran = array_any($request['effects'], $new)
                || array_any($request['events'] ?? [], fn (array $event) => is_string($event['at']) && AppTraces::onAddedLine($event['at'], $added));

            if ($request['test'] === null || $filter === null || ! isset($request['n']) || $request['cut'] || ! $ran) {
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

                // A save the job makes after it sent something: when it
                // fails, a queue tries the job again.
                $late = self::lateSave($ran);

                if ($late !== null) {
                    $jobs[] = [self::RETRY, $late, $effect, array_any($ran, $new), 'query'];
                }

                // What the app's code does after a job that ran here: in
                // use the job waits on a queue and has not run by then.
                [$its, $after] = $effect['kind'] === 'job' && self::ran($request['effects'], $place) !== [] ? self::around($request['effects'], $place) : [[], []];

                if ($after !== []) {
                    $jobs[] = [self::LATER, $place, $effect, array_any([...$its, ...$after], $new), 'later'];
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
                    'fault' => ['test' => $request['test'], 'request' => $request['n'], 'effect' => $at, 'kind' => $point[4] ?? $failed['kind']],
                    'times' => count(self::same($request['effects'], $failed)),
                    // A job is also the change's when the change wrote what it does.
                    'own' => $new($failed) || ($point[3] ?? false),
                    ...($fails === self::LATER ? ['was' => [
                        'status' => $request['status'],
                        'did' => self::did($request['effects']),
                        'listeners' => [],
                        'apart' => self::apart(self::around($request['effects'], $at)),
                    ]] : []),
                ];
            }

            // An event with listeners Laravel found by itself: the disk
            // gives their order, so the other order must do the same.
            foreach ($request['events'] ?? [] as $place => $event) {
                $points[implode('|', [self::REORDER, $route, $event['what'], $event['at'] ?? ''])] ??= [
                    'fails' => self::REORDER,
                    'route' => $route,
                    'failed' => 'event '.$event['what'],
                    'at' => $event['at'],
                    'test' => $request['test'],
                    'filter' => $filter,
                    'fault' => ['test' => $request['test'], 'request' => $request['n'], 'effect' => $place, 'kind' => 'event', 'what' => $event['what']],
                    'times' => 0,
                    // The event is the change's when the change dispatches it or wrote what a listener does.
                    'own' => (is_string($event['at']) && AppTraces::onAddedLine($event['at'], $added))
                        || array_any($request['effects'], fn (array $effect) => array_intersect($effect['frames'] ?? [], $event['listeners']) !== [] && $new($effect)),
                    'was' => [
                        'status' => $request['status'],
                        'did' => self::did($request['effects']),
                        'listeners' => $event['listeners'],
                        'apart' => self::apart(self::heard($request['effects'], $event['listeners'])),
                    ],
                ];
            }
        }

        $routes = array_fill_keys(array_column($suspected, 'route'), true);
        $lines = array_fill_keys(array_filter(array_column($suspected, 'at'), is_string(...)), true);
        $order = fn (array $point): array => [
            $point['own'] ? 0 : 1,
            isset($lines[$point['at'] ?? '']) || isset($routes[$point['route']]) ? 0 : 1,
            self::ORDER[$point['fails'] === self::RETRY ? 'job' : $point['fault']['kind']] ?? count(self::ORDER),
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
     * a missed place, or about a place that was not tried. An event or a
     * job that waits is missed too when its two runs have the same shape
     * but the shape cannot say that they left the same behind.
     *
     * @param  list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string, what?: string}, times: int, own: bool, was?: array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool}}>  $points  From points()
     * @param  array<int, list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>, blind: list<string>, cut: bool, n?: int, fault?: int}>>  $runs  What was recorded when each place's failure was caused, by the place's position in $points; a place not tried is absent
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
                // A run that is not whole in the trace cannot be compared.
                && ! (in_array($point['fails'], [self::AGAIN, self::RETRY, self::REORDER, self::LATER], true) && $request['cut']));

            if ($point === null || $hit === null || self::undecided($point, $hit)) {
                continue;
            }

            $run++;

            foreach (self::left($point['fails'], $hit, $point['times'], $point['was'] ?? null) as $kind => $effects) {
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
     * @param  array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool}|null  $was  What the request did in the tests' normal run, for an event or a job that waits
     * @return array<string, list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>>
     */
    protected static function left(string $fails, array $hit, int $times, ?array $was = null): array
    {
        $place = $hit['fault'] ?? 0;

        if ($fails === self::REORDER || $fails === self::LATER) {
            return array_filter([($fails === self::REORDER ? self::DEPENDS_ON_ORDER : self::NEEDS_JOB_DONE) => $was === null ? [] : self::changed($was, $hit)]);
        }

        if ($fails === self::AGAIN) {
            return array_filter([self::DONE_TWICE => self::twice($hit['effects'], $place)]);
        }

        if ($fails === self::RETRY) {
            return array_filter([self::SENT_AGAIN => self::sentAgain($hit['effects'], $place)]);
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
     * Determine if nothing can be said about an event whose listeners ran
     * in the reverse order, or a job that ran after the response: the
     * request did the same by shape, but two of the listeners, or the job
     * and what the request does after it, use one table and one of them
     * saves to it. The recorder keeps no values, so what stayed in that
     * table, or what was read from it, is not known.
     *
     * @param  array{fails: string, was?: array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool}}  $point
     * @param  array{status: int, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>, fault?: int}  $hit
     */
    protected static function undecided(array $point, array $hit): bool
    {
        if ($point['fails'] !== self::REORDER && $point['fails'] !== self::LATER) {
            return false;
        }

        $was = $point['was'] ?? null;

        if ($was === null) {
            return true;
        }

        $groups = $point['fails'] === self::REORDER ? self::heard($hit['effects'], $was['listeners']) : self::around($hit['effects'], $hit['fault'] ?? 0);

        return self::changed($was, $hit) === [] && ! ($was['apart'] && self::apart($groups));
    }

    /**
     * Get what a request did in another way when the listeners of one
     * event ran in the reverse order, or one job ran after the response:
     * each send, and each save of the
     * app's code that stayed, that one run made more times than the other
     * from the same line, and an answer with another status.
     *
     * @param  array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool}  $was
     * @param  array{status: int, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>}  $hit
     * @return list<array{kind: string, open: int, what: string, at: string|null}>
     */
    protected static function changed(array $was, array $hit): array
    {
        $before = self::byName($was['did']);
        $after = self::byName(self::did($hit['effects']));
        $changed = $hit['status'] === $was['status'] ? [] : [['kind' => 'answered', 'open' => 0, 'what' => "{$hit['status']}, not {$was['status']}", 'at' => null]];

        foreach ($before + $after as $key => $same) {
            $less = count($before[$key] ?? []) - count($after[$key] ?? []);

            if ($less !== 0) {
                $changed[] = ['kind' => $less > 0 ? 'missing' : 'added', 'open' => 0, 'what' => self::name($same[0]), 'at' => $same[0]['at'] ?? null];
            }
        }

        return $changed;
    }

    /**
     * Get what counts when two runs of a request are compared: what it
     * sent, and what the app's code saved that stayed.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function did(array $effects): array
    {
        $stayed = AppTraces::saved($effects);

        return array_values(array_filter(
            $effects,
            fn (array $effect, int $at) => in_array($effect['kind'], self::SENT, true) || (isset($stayed[$at]) && is_string($effect['at'] ?? null)),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Group things by their name and the line they came from.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @return array<string, non-empty-list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>>
     */
    protected static function byName(array $effects): array
    {
        $named = [];

        foreach ($effects as $effect) {
            $named[self::name($effect).'|'.($effect['at'] ?? '')][] = $effect;
        }

        return $named;
    }

    /**
     * Determine if groups of things leave each other's tables alone: no
     * table that two groups use has a save from one of them.
     *
     * @param  list<list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>>  $groups
     */
    protected static function apart(array $groups): bool
    {
        $saves = [];

        foreach ($groups as $group => $effects) {
            foreach ($effects as $effect) {
                foreach (self::tables($effect['sql'] ?? '') as $table) {
                    $saves[$table][$group] = ($saves[$table][$group] ?? false) || AppTraces::writes($effect);
                }
            }
        }

        return ! array_any($saves, fn (array $by) => count($by) > 1 && in_array(true, $by, true));
    }

    /**
     * Get what each listener of an event did. A thing is a listener's
     * when the listener is among the app's code on the way to it.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>  $effects
     * @param  list<string>  $listeners  As Class::method
     * @return list<list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>>
     */
    protected static function heard(array $effects, array $listeners): array
    {
        return array_map(
            fn (string $listener) => array_values(array_filter($effects, fn (array $effect) => in_array($listener, $effect['frames'] ?? [], true))),
            $listeners,
        );
    }

    /**
     * Get what happened after a job was dispatched, in two groups: what
     * jobs on the sync queue did, and what the app's code did in the
     * request itself.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>  $effects
     * @return array{list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>, list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>}
     */
    protected static function around(array $effects, int $place): array
    {
        $after = array_slice($effects, $place + 1);

        return [
            array_values(array_filter($after, fn (array $effect) => $effect['job'] ?? false)),
            array_values(array_filter($after, fn (array $effect) => ! ($effect['job'] ?? false) && is_string($effect['at'] ?? null))),
        ];
    }

    /**
     * Get the tables a query names.
     *
     * @return list<string>
     */
    protected static function tables(string $sql): array
    {
        preg_match_all('/\b(?:from|join|into|update)\s+[`"\[]?([\w.]+)/i', $sql, $found);

        // "on conflict do update set" names no table.
        return array_values(array_diff(array_unique(array_map(strtolower(...), $found[1])), ['set']));
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
     * @param  array<int, array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
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
     * Get what a job sent before its save failed and sent again, from the
     * same line, when it was tried again. A job that took the failure in
     * was not tried again, and nothing is said.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function sentAgain(array $effects, int $place): array
    {
        $marker = array_find_key($effects, fn (array $effect, int $at) => $at > $place && ($effect['again'] ?? false));

        if ($marker === null) {
            return [];
        }

        $again = self::ran($effects, $marker);
        $sent = [];

        // The job's first run, back from the save that failed.
        for ($at = $place - 1; $effects[$at]['job'] ?? false; $at--) {
            if (in_array($effects[$at]['kind'], self::SENT, true) && self::same($again, $effects[$at]) !== []) {
                array_unshift($sent, $effects[$at]);
            }
        }

        return $sent;
    }

    /**
     * Get the place of the last save a job made after it sent something,
     * when there is one.
     *
     * @param  array<int, array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $ran  From ran()
     */
    protected static function lateSave(array $ran): ?int
    {
        $sent = false;
        $late = null;

        foreach ($ran as $at => $effect) {
            if (in_array($effect['kind'], self::SENT, true)) {
                $sent = true;
            } elseif ($sent && AppTraces::writes($effect)) {
                $late = $at;
            }
        }

        return $late;
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
