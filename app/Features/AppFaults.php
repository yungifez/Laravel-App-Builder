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
 * three: in use that job runs later, by itself. An email or an outside
 * call the job makes is still made to fail in it, for one question only:
 * does the job hide the failure (the last thing below).
 *
 * A fourth thing is read from a job that ran twice: what it sent or added
 * both times. A queue gives a job to a worker at least once, so a job
 * must be safe to run again. The recorder keeps no values, so only the
 * shape is compared: the same send or the same insert from the same line
 * in both runs. A change that is made again (an update, a delete, or an
 * insert that says what to do when the row is there) is not held against
 * the job. An outside call the service can take twice is not held
 * against it either: a GET, a PUT, a DELETE, or a call that says which
 * call it is (an idempotency key). A job of the framework that delivers
 * one email, notification or broadcast is not run twice: it has no code
 * of the app to make safe.
 *
 * A fifth thing is read when an outside call gets no answer: the app
 * makes the same call again. A call that got no answer may still have
 * arrived, so the service can do it twice (a payment taken twice). It is
 * a finding for a POST or a PATCH that does not say which call it is
 * (an idempotency key). A GET, a PUT and a DELETE can be made again.
 *
 * A sixth thing is read when an outside call is answered with an error.
 * Laravel's HTTP client gives the app such an answer and throws nothing,
 * so an app that does not ask carries on as if the call worked. The
 * call is made, and a server error is its answer. It is a finding when
 * the app's code did not ask that answer for its status, and the request
 * went on to send and save the same as when the call works. Only a call
 * the app's code makes itself, and does more after, is such a place.
 *
 * A job has a second place: the last save it makes after it sent
 * something. That save is refused, and the job is run again, the way a
 * queue tries a failed job again. A job that asks if it ran before, but
 * marks that only after it sent, passes the run above and sends twice
 * here.
 *
 * The other way round is read too. A job that marks its work as done
 * before it sends, and then fails with its email, is tried again by the
 * queue. The second try sees the mark and stops, so the email is never
 * sent. The job is run again after its email failed, and the second run
 * must send from the same line. An outside call is left out of this: a
 * call that got no answer may have arrived, so a job that does not make
 * it again can be right.
 *
 * The same second run shows a job that sends to many. Such a job that
 * fails at one email starts from the top when it is tried again, and
 * sends again what it had sent before the failure. So the last email a
 * job sends from one line is the one that fails, and no email may leave
 * more times than in the normal run.
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
 *
 * The job must do the same too. A worker of a queue has no request: no
 * one is signed in, and what the person sent and their session are not
 * there. The job that was held back runs that way. A job that takes
 * the person from the request it was dispatched in, and not from what
 * it was given, then sends or saves less. That is a finding of its own.
 *
 * One thing is read from a request or a command that sends to many: the
 * same email from one line, more than once. The first one is made to
 * fail, and the others must still leave. A loop that lets the first
 * failure through sends nothing to the people after it. A job is left
 * out: a queue tries a failed job again. An outside call is left out
 * too: a loop that reads pages from a service is right to stop when one
 * call fails.
 *
 * A file the app writes to one of its disks (Storage) is a place too: the
 * disk does not take the file. Laravel then gives the app's code false,
 * and throws only when the disk's config says so. An app that does not
 * ask what the write gave back carries on as if the file is there. That
 * is read the same way as a failure the app's code catches (the last
 * thing below). A file is not counted among what the app sent: a file
 * that stays after a save was lost is not held against the change.
 *
 * A file the app deletes is read when a save after it is lost. Nothing
 * puts a deleted file back, so what the app kept still points to a file
 * that is gone. A save in steps after a delete is a place for that reason.
 * The same is read when a send fails after the delete, and the request
 * then does not make a save it makes when all works. A file the app
 * moves counts the same: what the app kept still points to where the
 * file was. A move is also made to fail, the way a write is.
 *
 * The last thing is read when the app's code catches the failure. A
 * request that then did nothing new, gave the same kind of answer as when
 * all worked, and wrote nothing to its log hid the failure: the person
 * is not told, and no one can find out later. The recorder keeps no
 * values, so the answer is compared by its names only: its status, the
 * route it sends the person to, and what it tells them. A log that
 * cannot be seen, such as one a test put a fake in place of, says
 * nothing.
 *
 * An artisan command of the app's own code is a place too (see
 * AppTraces). The schedule runs it with no one there, so no answer tells
 * a person of a failure: only its log and how it ended do.
 *
 * A job a test runs with no request or command around it is a place too.
 * Most tests of a request put a fake in place of the queue, so the job's
 * own test is where the job runs. It is run a second time, and tried again
 * after its last save or its email failed, the same way as a job a request
 * dispatched.
 * It is not held back: nothing ran before it that it could need. An email
 * or an outside call it makes is made to fail in it too. A queue takes a
 * job that ends without an error as done, so a job that catches that
 * failure and writes nothing to the log hid it.
 */
class AppFaults
{
    public const SAVED_THEN_FAILED = 'saved_then_failed';

    public const SENT_THEN_LOST = 'sent_then_lost';

    public const SAVED_IN_PART = 'saved_in_part';

    public const FILE_GONE = 'file_gone';

    public const DONE_TWICE = 'done_twice';

    public const CALLED_AGAIN = 'called_again';

    public const SENT_AGAIN = 'sent_again';

    public const NEVER_SENT = 'never_sent';

    public const REST_NOT_SENT = 'rest_not_sent';

    public const DEPENDS_ON_ORDER = 'depends_on_order';

    public const NEEDS_JOB_DONE = 'needs_job_done';

    public const JOB_NEEDS_REQUEST = 'job_needs_request';

    public const ANSWER_NOT_CHECKED = 'answer_not_checked';

    public const FAILURE_HIDDEN = 'failure_hidden';

    /**
     * The findings the owner reads in the proof, and so may accept.
     */
    public const OWNED = [
        self::SAVED_THEN_FAILED,
        self::SENT_THEN_LOST,
        self::SAVED_IN_PART,
        self::FILE_GONE,
        self::FAILURE_HIDDEN,
        self::DONE_TWICE,
        self::SENT_AGAIN,
        self::NEVER_SENT,
        self::REST_NOT_SENT,
        self::CALLED_AGAIN,
        self::ANSWER_NOT_CHECKED,
        self::NEEDS_JOB_DONE,
        self::JOB_NEEDS_REQUEST,
        self::DEPENDS_ON_ORDER,
    ];

    public const SEND = 'send';

    public const SAVE = 'save';

    public const AGAIN = 'again';

    public const RETRY = 'retry';

    public const REORDER = 'reorder';

    public const LATER = 'later';

    public const ANSWER = 'answer';

    /**
     * What the recorder can make fail when the app sends or stores it.
     */
    protected const FAILS = ['mail', 'http', 'file'];

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
    protected const ORDER = ['http' => 0, 'mail' => 0, 'file' => 0, 'answer' => 0, 'job' => 1, 'later' => 1, 'event' => 1, 'query' => 2];

    /**
     * How Pest starts the method it makes for a test named by a sentence.
     */
    protected const SENTENCE = '__pest_evaluable_';

    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 40;

    /**
     * Find the places where a failure can be caused: each email, outside
     * call and file write a request makes, also from the app's code in a job, each outside
     * call the app's code makes itself and does more after, the last save
     * of each transaction a request commits, the last save the app's code
     * makes outside a transaction once the request has saved, sent or
     * deleted a file, each job the sync queue ran that sent or added something,
     * each job that sent or saved something or that the request does more
     * after, and each event with found listeners to run in the reverse
     * order. Only requests that ran the change's code are used.
     *
     * One place is tried in one test: the first that reaches it. A send
     * or a save takes the first test where the app's log can be seen, when
     * there is one: only there is a failure the app hides found.
     *
     * The places come in the order to try them, so a small budget goes to
     * the ones that tell the most: places on the change's own lines, then
     * places another reading of the change suspects (the same line, or the
     * same route), then what cannot be taken back before what can, and
     * last what a job of a request sends. The order comes only from the
     * trace, the patch and those findings, so the same change gives the
     * same order.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, direct?: bool, frames?: list<string>}>, blind: list<string>, cut: bool, n?: int, fault?: int, dark?: bool, shape?: list<string>, events?: list<array{what: string, at: string|null, listeners: list<string>}>}>  $requests  From AppTraces::parse(), of the tests' normal run
     * @param  list<array{route: string, at?: string|null}>  $suspected  Findings of the other engines about the change, such as AppTraces and AppBoundaries give
     * @return list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string, what?: string}, times: int, own: bool, job?: bool, was?: array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool, shape?: list<string>}}>
     */
    public static function points(array $requests, ?string $patch, array $suspected = []): array
    {
        $added = AppTraces::addedLines($patch);
        $new = fn (array $effect): bool => is_string($effect['at'] ?? null) && AppTraces::onAddedLine($effect['at'], $added);
        $points = [];
        $dark = [];
        $inner = [];

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
            $alone = $request['method'] === AppTraces::JOB;
            $stayed = AppTraces::saved($request['effects']);
            $found = [];
            $jobs = [];
            $answers = [];
            $last = null;
            $apart = null;
            $done = false;

            foreach ($request['effects'] as $place => $effect) {
                // An outside call the app's code made itself and did more
                // after: its answer can be an error.
                $more = $effect['kind'] === 'http' && ($effect['direct'] ?? false) ? self::after($request['effects'], $place) : [];

                if ($more !== []) {
                    $answers[] = [self::ANSWER, $place, $effect, array_any($more, $new), 'answer'];
                }

                if ($effect['job'] ?? false) {
                    // What the app's code sends in a job can fail there. The last
                    // send from one line is the place: what the job sent before
                    // it is then seen when the job is tried again.
                    if (self::fails($effect) && is_string($effect['at'] ?? null) && ! self::inside($request['effects'], $place)) {
                        $found[self::name($effect).'|'.$effect['at']] = [self::SEND, $place, $effect];
                    }

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

                // A job that ran here: in use it waits on a queue and runs
                // after the response, where no one is signed in. What the
                // app's code does after it has not waited for it.
                $does = $effect['kind'] === 'job' ? self::ran($request['effects'], $place) : [];
                [$its, $after] = $does !== [] ? self::around($request['effects'], $place) : [[], []];

                if (! $alone && ($after !== [] || array_any($does, fn (array $effect, int $at) => in_array($effect['kind'], self::SENT, true) || (isset($stayed[$at]) && is_string($effect['at'] ?? null))))) {
                    $jobs[] = [self::LATER, $place, $effect, array_any([...$its, ...$after], $new), 'later'];
                }

                if (self::fails($effect)) {
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

                $done = $done || in_array($effect['kind'], self::SENT, true) || self::gone($effect) || (isset($stayed[$place]) && is_string($effect['at'] ?? null));
            }

            foreach ([...$found, ...($apart === null ? [] : [$apart]), ...$answers, ...$jobs] as $point) {
                [$fails, $at, $failed] = $point;
                $key = implode('|', [$fails, $route, self::name($failed), $failed['at'] ?? '']);
                // A test where the app's log cannot be seen gives its
                // place to a later test where it can.
                $seen = ($dark[$key] ?? false) && ! ($request['dark'] ?? false) && in_array($fails, [self::SEND, self::SAVE], true);
                // A test where a job sends the same thing more times shows more of what it sends again.
                $more = isset($points[$key]) && $fails === self::SEND && ($failed['job'] ?? false)
                    && ($dark[$key] || ! ($request['dark'] ?? false))
                    && count(self::same($request['effects'], $failed)) > $points[$key]['times'];

                if (isset($points[$key]) && ! $seen && ! $more) {
                    continue;
                }

                $dark[$key] = $request['dark'] ?? false;
                $inner[$key] = $fails === self::SEND && ! $alone && ($failed['job'] ?? false);
                $points[$key] = [
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
                    // The failure is in a job the request queued, not in the request.
                    ...($inner[$key] ? ['job' => true] : []),
                    // What the failure is compared with: the same request when all worked.
                    ...(in_array($fails, [self::LATER, self::ANSWER, self::SEND, self::SAVE, self::RETRY], true) ? ['was' => [
                        'status' => $request['status'],
                        'did' => self::did($request['effects']),
                        'listeners' => [],
                        'apart' => $fails !== self::LATER || self::apart(self::around($request['effects'], $at)),
                        ...(isset($request['shape']) ? ['shape' => $request['shape']] : []),
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
        $order = fn (string $key): array => [
            $points[$key]['own'] ? 0 : 1,
            isset($lines[$points[$key]['at'] ?? '']) || isset($routes[$points[$key]['route']]) ? 0 : 1,
            // A send in a job of a request answers one question only, so it comes after the rest.
            ($inner[$key] ?? false) ? count(self::ORDER) : self::ORDER[$points[$key]['fails'] === self::RETRY ? 'job' : $points[$key]['fault']['kind']] ?? count(self::ORDER),
        ];
        $keys = array_keys($points);

        // Places that are alike stay in the order the trace gave them.
        usort($keys, fn (string $one, string $other) => $order($one) <=> $order($other));

        return array_map(fn (string $key) => $points[$key], $keys);
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
     * @param  list<array{fails: string, route: string, failed: string, at: string|null, test: string, filter: string, fault: array{test: string, request: int, effect: int, kind: string, what?: string}, times: int, own: bool, job?: bool, was?: array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool, shape?: list<string>}}>  $points  From points()
     * @param  array<int, list<array{test: string|null, method: string, route: string|null, status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool, frames?: list<string>}>, blind: list<string>, cut: bool, n?: int, fault?: int, asked?: bool, quiet?: bool, shape?: list<string>}>>  $runs  What was recorded when each place's failure was caused, by the place's position in $points; a place not tried is absent
     * @return array{points: int, run: int, missed: int, existing: int, findings: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string, job?: bool}>}|null
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
                && ! (in_array($point['fails'], [self::AGAIN, self::RETRY, self::REORDER, self::LATER, self::ANSWER], true) && $request['cut']));

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
                    ...(($point['job'] ?? false) ? ['job' => true] : []),
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
     * Name a finding by what it is, not where: what was found, the address
     * and what was made to fail. The line moves while the change is fixed.
     *
     * @param  array{kind: string, route: string, failed: string}  $finding
     */
    public static function identity(array $finding): string
    {
        return implode('|', [$finding['kind'], $finding['route'], $finding['failed']]);
    }

    /**
     * Set aside the findings a person said the change makes on purpose, by
     * what they are (identity()). They are counted as "accepted", not held
     * against the change.
     *
     * @param  array{points: int, run: int, missed: int, existing: int, findings: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string, job?: bool}>, accepted?: int}|null  $measured  From measure()
     * @param  list<string>  $accepted  The identities accepted
     * @return array{points: int, run: int, missed: int, existing: int, findings: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string, job?: bool}>, accepted?: int}|null
     */
    public static function without(?array $measured, array $accepted): ?array
    {
        if ($measured === null || $accepted === []) {
            return $measured;
        }

        $findings = array_values(array_filter($measured['findings'], fn (array $finding) => ! in_array(self::identity($finding), $accepted, true)));

        return [
            ...$measured,
            'findings' => $findings,
            'accepted' => ($measured['accepted'] ?? 0) + count($measured['findings']) - count($findings),
        ];
    }

    /**
     * Say what the app left behind at one place, as the trace of the
     * caused failure shows it.
     *
     * @param  array{kind: string, route: string, failed: string, what: string, at: string|null, test: string, job?: bool}  $finding  From measure()
     */
    public static function describe(array $finding): string
    {
        $at = fn (string $before, string $after = '') => $finding['at'] === null ? '' : "{$before} {$finding['at']}{$after}";
        // An artisan command, or a job that ran by itself, has no response and no status: it ends well or it does not.
        $command = AppTraces::command($finding['route']) !== null;
        $job = AppTraces::job($finding['route']) !== null;
        $run = match (true) {
            $command => 'command',
            $job => 'job',
            default => 'request',
        };
        $end = $command || $job ? "the {$run} ended" : 'the response';
        // A disk gives false for a write that failed, so no code has to catch anything to go on.
        $hid = match (true) {
            $finding['failed'] === 'file move' => 'went on as if the file was moved',
            str_starts_with($finding['failed'], 'file ') => 'went on as if the file was stored',
            default => 'caught the failure and hid it',
        };
        $gone = str_contains($finding['what'], 'file move') ? 'moved a file' : 'deleted a file';
        // A save that fails is lost itself. A send that fails stops the save after it.
        $lost = array_any(self::FAILS, fn (string $kind) => str_starts_with($finding['failed'], "{$kind} ")) ? "the {$run} did not make a save it makes when all works, but had already {$gone}" : "the save was lost but the {$run} had already {$gone}";

        $said = match ($finding['kind']) {
            self::DONE_TWICE => "when {$finding['failed']}{$at(', queued at', ',')} ran a second time, it sent or added the same thing again: {$finding['what']}",
            self::SENT_AGAIN => str_starts_with($finding['failed'], 'job ')
                ? "when a save failed in {$finding['failed']}{$at(', queued at', ',')} and the job was tried again, it sent the same thing again: {$finding['what']}"
                : "when {$finding['failed']} failed{$at(' at')} and the job was tried again, the job started from the top and sent again what it had sent before the failure: {$finding['what']}",
            self::NEVER_SENT => "when {$finding['failed']} failed{$at(' at')} and the job was tried again, the second try did not send it: what the first try left behind made the job stop, so it is never sent",
            self::REST_NOT_SENT => "when {$finding['failed']} failed{$at(' at')}, one failure stopped the rest: the {$run} sends from that line more than once when all works, and it did not send the others",
            self::CALLED_AGAIN => "when {$finding['failed']}{$at(' at')} got no answer, the {$run} made the same call again with no idempotency key, so the service may do it twice",
            self::ANSWER_NOT_CHECKED => "when {$finding['failed']}{$at(' at')} was answered with a server error, the app's code did not ask the answer for its status and the {$run} went on as if the call worked: {$finding['what']}",
            self::NEEDS_JOB_DONE => "when {$finding['failed']}{$at(', queued at', ',')} ran after {$end}, the way a queue runs it, the {$run} did not do the same: {$finding['what']}",
            self::JOB_NEEDS_REQUEST => "when {$finding['failed']}{$at(', queued at', ',')} ran after {$end}, the way a queue worker runs it, with no signed-in user and an empty request and session, the job did not do the same: {$finding['what']}",
            self::DEPENDS_ON_ORDER => "when the listeners Laravel found for {$finding['failed']}{$at(', dispatched at', ',')} ran in the reverse order, the {$run} did not do the same: {$finding['what']}",
            self::SAVED_THEN_FAILED => "when {$finding['failed']} failed{$at(' at')}, the {$run} ended in ".($command ? 'an error' : 'a server error')." but had already saved: {$finding['what']}",
            self::SENT_THEN_LOST => "when {$finding['failed']} failed{$at(' at')}, the save was lost but the {$run} had already sent: {$finding['what']}",
            self::SAVED_IN_PART => "when {$finding['failed']} failed{$at(' at')}, the save was lost but the {$run} kept what it had saved before it, with no transaction around both: {$finding['what']}",
            self::FILE_GONE => "when {$finding['failed']} failed{$at(' at')}, {$lost}, and nothing puts it back: {$finding['what']}",
            self::FAILURE_HIDDEN => ($finding['job'] ?? false)
                ? "when {$finding['failed']} failed{$at(' at')}, in a job the {$run} queued, the job {$hid}: it ended with no error, did nothing new, and wrote nothing to the log"
                : "when {$finding['failed']} failed{$at(' at')}, the app {$hid}: the {$run} did nothing new, ".($command || $job ? 'ended the same' : 'gave the same kind of answer').' as when all worked, and wrote nothing to the log',
            default => "when {$finding['failed']} failed{$at(' at')}, {$finding['kind']}: {$finding['what']}",
        };

        return "{$finding['route']}: {$said} (caused in {$finding['test']})";
    }

    /**
     * Say what a finding is and how to fix it, for the coder. A finding
     * sends the change back by itself: the failure was caused and the
     * trace shows what stayed.
     *
     * @param  array{kind: string, route: string, failed: string, what: string, at: string|null, test: string, job?: bool}  $finding  From measure()
     */
    public static function finding(array $finding): string
    {
        $command = AppTraces::command($finding['route']) !== null;

        $moved = 'Move the file after the save, in the same DB::transaction(), and throw when move() gives false: the transaction then puts the save back.';

        $fix = match (true) {
            $finding['kind'] === self::FAILURE_HIDDEN && $finding['failed'] === 'file move' => "A move on a disk that fails gives false, and throws only when the disk's config has 'throw' => true. Ask what move() gave back. Then do not go on as if the file is at its new place. {$moved}",
            ! $command && $finding['kind'] === self::SAVED_THEN_FAILED && $finding['failed'] === 'file move' => "A person who sees the error tries again, and the save happens twice. {$moved}",
            $finding['kind'] === self::FILE_GONE && str_contains($finding['what'], 'file move') => "What the app kept still points to where the file was. {$moved}",
            $finding['kind'] === self::FAILURE_HIDDEN && str_starts_with($finding['failed'], 'file ') => "A write to a disk that fails gives false, and throws only when the disk's config has 'throw' => true. Ask what put(), store(), storeAs() or copy() gave back, or set 'throw' => true for the disk. Then do not go on as if the file is there: tell the person what did not happen, or let the job or the command fail.",
            ($finding['job'] ?? false) && $finding['kind'] === self::FAILURE_HIDDEN => 'A queue takes a job that ends without an error as done, and does not try it again. Let the job fail, or record the failure with report().',
            ! $command && $finding['kind'] === self::SAVED_THEN_FAILED && str_starts_with($finding['failed'], 'file ') => 'A person who sees the error tries again, and the save happens twice. Store the file before the save, so a write that fails leaves nothing behind. Or catch the failure, record it with report(), and tell the person what did not happen.',
            $command && $finding['kind'] === self::SAVED_THEN_FAILED => 'The schedule runs the command again, and what the failed run saved is still there: the command then skips that work or does it twice. Save that the work is done only after the send worked, or make the command safe to run again.',
            $command && $finding['kind'] === self::FAILURE_HIDDEN => 'No one reads what a command prints when the schedule runs it. Let it fail, or record the failure with report().',
            $finding['kind'] === self::SENT_AGAIN && ! str_starts_with($finding['failed'], 'job ') => 'A queue tries a failed job again from the top. Send each email from its own job (queue the email, or dispatch one job for each person). Or record each one before the job sends it, and take only that record back when its send fails.',
            AppTraces::job($finding['route']) !== null && $finding['kind'] === self::FAILURE_HIDDEN => 'A queue takes a job that ends without an error as done, and does not try it again. Let the job fail, or record the failure with report().',
            default => self::fix($finding['kind']),
        };

        return trim(__(':said. :fix', ['said' => self::describe($finding), 'fix' => $fix]));
    }

    /**
     * Say how to fix a finding of one kind.
     */
    protected static function fix(string $kind): string
    {
        return match ($kind) {
            self::SAVED_THEN_FAILED => 'A person who sees the error tries again, and the save happens twice. Queue what the request sends, after the save is kept. Or catch the failure, record it with report(), and tell the person what did not happen.',
            self::SENT_THEN_LOST => 'People are told about something that was not saved. Send after the save is kept: after the transaction, or with afterCommit().',
            self::SAVED_IN_PART => 'Put the saves that belong together in one DB::transaction().',
            self::FILE_GONE => 'What the app kept still points to a file that is gone. Delete the file last, after the save is kept: after the transaction, or in DB::afterCommit().',
            self::FAILURE_HIDDEN => 'No one finds a failure that the code catches and does not record. Let it fail, or record it with report() and tell the person what did not happen.',
            self::DONE_TWICE => 'A queue gives a job to a worker at least once. Make the job safe to run again: look for what it already made (firstOrCreate, a unique index), or record that it sent before it sends, and take that record back when the send fails. Give an outside call an Idempotency-Key header with the same value on each run.',
            self::SENT_AGAIN => 'A queue tries a failed job again. Save first and send last in the job, and take the save back when the send fails, so the next try sends.',
            self::NEVER_SENT => 'A queue tries a failed job again, and that try must send what the first could not. When the send fails, take back what the job saved before it: catch the failure, undo the save, and throw the failure again.',
            self::REST_NOT_SENT => 'One failure must not stop the rest. Queue each one: Mail::to()->queue(), a notification that implements ShouldQueue, or one job for each person. Or catch the failure for each one, record it with report(), and go on with the next.',
            self::CALLED_AGAIN => 'A call that got no answer can still have arrived. Give the call an Idempotency-Key header with the same value on each try. When the service takes none, do not try the call again.',
            self::ANSWER_NOT_CHECKED => 'The HTTP client of Laravel throws nothing for an error answer. Call throw() on the answer, or ask successful() or failed() and stop when the call did not work.',
            self::NEEDS_JOB_DONE => 'Tests run a queued job where it is dispatched. In use a queue runs it after the response. Do what the request needs before it answers in the request, or run that work with dispatchSync().',
            self::JOB_NEEDS_REQUEST => 'A queue worker has no request. Give the job what it needs through its constructor, and do not read auth(), request() or session() in it. For a row the request deletes after it queues the job, give the job the values, not the model.',
            self::DEPENDS_ON_ORDER => 'Laravel takes the listeners it finds in the order the disk lists their files. Put steps that need an order in one listener, or have the second step listen to an event the first one dispatches.',
            default => '',
        };
    }

    /**
     * Get the findings of one kind.
     *
     * @param  array{findings?: list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string, job?: bool}>}|null  $measured  From measure()
     * @return list<array{kind: string, route: string, failed: string, what: string, at: string|null, test: string, job?: bool}>
     */
    public static function findings(?array $measured, string $kind): array
    {
        return array_values(array_filter($measured['findings'] ?? [], fn (array $finding) => $finding['kind'] === $kind));
    }

    /**
     * Get what a request left behind after its failure that the failure
     * should have stopped or undone, by the kind of problem it is.
     *
     * @param  array{status: int, refused: bool, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, cut: bool, fault?: int, asked?: bool, quiet?: bool, shape?: list<string>}  $hit  The request the failure was caused in
     * @param  int  $times  How many times the request did the same thing from the same line in the tests' normal run
     * @param  array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool, shape?: list<string>}|null  $was  What the request did in the tests' normal run
     * @return array<string, list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>>
     */
    protected static function left(string $fails, array $hit, int $times, ?array $was = null): array
    {
        $place = $hit['fault'] ?? 0;

        if ($fails === self::REORDER) {
            return array_filter([self::DEPENDS_ON_ORDER => $was === null ? [] : self::changed($was, $hit)]);
        }

        if ($fails === self::LATER) {
            // What the job did in another way is the job's: it ran the way
            // a worker runs it. The rest is the request's, which did not
            // wait for the job.
            $changed = $was === null ? [] : self::changed($was, $hit);
            $its = fn (array $effect): bool => $effect['job'] ?? false;

            return array_filter([
                self::NEEDS_JOB_DONE => array_values(array_filter($changed, fn (array $effect) => ! $its($effect))),
                self::JOB_NEEDS_REQUEST => array_values(array_filter($changed, $its)),
            ]);
        }

        if ($fails === self::ANSWER) {
            // An app that asked how the call went, or did something else,
            // took the error in.
            $same = $was !== null && ! ($hit['asked'] ?? false) && self::changed($was, $hit) === [];

            return array_filter([self::ANSWER_NOT_CHECKED => $same ? self::after($hit['effects'], $place) : []]);
        }

        if ($fails === self::AGAIN) {
            return array_filter([self::DONE_TWICE => self::twice($hit['effects'], $place)]);
        }

        if ($fails === self::RETRY) {
            return array_filter([self::SENT_AGAIN => self::sentTwice($hit, $place, $was)]);
        }

        $before = fn (array $effect, int $at): bool => $at < $place && ! ($effect['job'] ?? false);
        $stayed = AppTraces::saved($hit['effects']);
        // What the framework or a package saved by itself is not part of
        // what the app was saving.
        $kept = array_values(array_filter($stayed, fn (array $effect, int $at) => $before($effect, $at) && is_string($effect['at'] ?? null), ARRAY_FILTER_USE_BOTH));

        if ($fails === self::SEND) {
            return array_filter([
                // The person got an error page, but what the request saved
                // before the failure is still there. A send that failed in
                // a job fails on the queue in use, after the answer.
                self::SAVED_THEN_FAILED => $hit['status'] >= 500 && ! ($hit['effects'][$place]['job'] ?? false) ? $kept : [],
                self::CALLED_AGAIN => self::calledAgain($hit['effects'], $place, $times),
                self::NEVER_SENT => self::neverSent($hit, $place),
                self::REST_NOT_SENT => self::stopped($hit, $place, $times),
                self::FILE_GONE => self::unsaved($hit, $place, $was),
                self::SENT_AGAIN => self::sentTwice($hit, $place, $was),
                self::FAILURE_HIDDEN => self::hidden($hit, $was),
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

        if ($failed === null || array_any($stayed, $again)) {
            return [];
        }

        if ($tookIn) {
            return array_filter([self::FAILURE_HIDDEN => self::hidden($hit, $was)]);
        }

        $sent = array_values(array_filter($hit['effects'], fn (array $effect, int $at) => $before($effect, $at) && in_array($effect['kind'], self::SENT, true), ARRAY_FILTER_USE_BOTH));
        $gone = array_values(array_filter($hit['effects'], fn (array $effect, int $at) => $before($effect, $at) && self::gone($effect), ARRAY_FILTER_USE_BOTH));

        return array_filter([self::SENT_THEN_LOST => $sent, self::SAVED_IN_PART => $kept, self::FILE_GONE => $gone, self::FAILURE_HIDDEN => self::hidden($hit, $was)]);
    }

    /**
     * Get the thing that failed when the app hid its failure: the app's
     * code caught it and wrote nothing to the log, and the request did
     * nothing it does not do when all works and gave the same kind of
     * answer. What the request did less is the failure itself. An app
     * that tells the person, records the failure or does something else
     * about it did not hide it.
     *
     * @param  array{status: int, effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, cut: bool, fault?: int, quiet?: bool, shape?: list<string>}  $hit
     * @param  array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool, shape?: list<string>}|null  $was
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function hidden(array $hit, ?array $was): array
    {
        $failed = $hit['effects'][$hit['fault'] ?? 0] ?? null;

        // A run that is not whole in the trace, or an answer whose shape
        // is not known for both runs, cannot be compared.
        if ($failed === null || $was === null || ! ($hit['quiet'] ?? false) || $hit['cut'] || ! isset($was['shape'], $hit['shape']) || $was['shape'] !== $hit['shape']) {
            return [];
        }

        return array_all(self::changed($was, $hit), fn (array $change) => $change['kind'] === 'missing') ? [$failed] : [];
    }

    /**
     * Determine if nothing can be said about an event whose listeners ran
     * in the reverse order, or a job that ran after the response: the
     * request did the same by shape, but two of the listeners, or the job
     * and what the request does after it, use one table and one of them
     * saves to it. The recorder keeps no values, so what stayed in that
     * table, or what was read from it, is not known.
     *
     * @param  array{fails: string, was?: array{status: int, did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, listeners: list<string>, apart: bool, shape?: list<string>}}  $point
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
     * @return list<array{kind: string, open: int, what: string, at: string|null, job?: bool}>
     */
    protected static function changed(array $was, array $hit): array
    {
        $before = self::byName($was['did']);
        $after = self::byName(self::did($hit['effects']));
        $changed = $hit['status'] === $was['status'] ? [] : [['kind' => 'answered', 'open' => 0, 'what' => "{$hit['status']}, not {$was['status']}", 'at' => null]];

        foreach ($before + $after as $key => $same) {
            $less = count($before[$key] ?? []) - count($after[$key] ?? []);

            if ($less !== 0) {
                $changed[] = ['kind' => $less > 0 ? 'missing' : 'added', 'open' => 0, 'what' => self::name($same[0]), 'at' => $same[0]['at'] ?? null, ...(($same[0]['job'] ?? false) ? ['job' => true] : [])];
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
     * Get what the app went on to do after an outside call: what it
     * sent, and what its code saved that stayed. For a call a job made,
     * only the rest of that job counts: in use the request has ended by
     * then.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function after(array $effects, int $place): array
    {
        $stayed = AppTraces::saved($effects);
        $rest = ($effects[$place]['job'] ?? false)
            ? self::ran($effects, $place)
            : array_filter(array_slice($effects, $place + 1, null, true), fn (array $effect) => ! ($effect['job'] ?? false));

        return array_values(array_filter(
            $rest,
            fn (array $effect, int $at) => in_array($effect['kind'], self::SENT, true) || (isset($stayed[$at]) && is_string($effect['at'] ?? null)),
            ARRAY_FILTER_USE_BOTH,
        ));
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
     * Get the email that stopped the others when it failed: the request
     * or the command sends the same email from that line more than once
     * when all works, and after the failure it did not send the rest. A
     * send in a job is left out: a queue tries a failed job again. An
     * outside call is left out: the calls of a loop can need each other.
     *
     * @param  array{effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, cut: bool}  $hit
     * @param  int  $times  How many times the request did the same thing from the same line in the tests' normal run
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function stopped(array $hit, int $place, int $times): array
    {
        $failed = $hit['effects'][$place] ?? null;

        if ($failed === null || $failed['kind'] !== 'mail' || $times < 2 || $hit['cut'] || ($failed['job'] ?? false) || ! is_string($failed['at'] ?? null)) {
            return [];
        }

        // The one that failed is in the trace too, and did not leave.
        return count(self::same($hit['effects'], $failed)) < $times ? [$failed] : [];
    }

    /**
     * Get the files a request deleted before a send that failed, when the
     * request then did not make a save it makes when all works: what the
     * app kept still names the files. A send in a job is left out: it
     * fails on the queue in use, after the request saved. A run that is
     * not whole in the trace says nothing.
     *
     * @param  array{effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, cut: bool}  $hit
     * @param  array{did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>}|null  $was
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function unsaved(array $hit, int $place, ?array $was): array
    {
        $failed = $hit['effects'][$place] ?? null;

        if ($failed === null || $was === null || $hit['cut'] || ($failed['job'] ?? false)) {
            return [];
        }

        $saves = fn (array $effects): array => self::byName(array_values(array_filter($effects, fn (array $effect) => isset($effect['sql']))));
        $made = $saves(self::did($hit['effects']));

        if (! array_any($saves($was['did']), fn (array $same, string $key) => count($same) > count($made[$key] ?? []))) {
            return [];
        }

        return array_values(array_filter($hit['effects'], fn (array $effect, int $at) => $at < $place && ! ($effect['job'] ?? false) && self::gone($effect), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Get the email a job did not send when it was tried again after that
     * email failed in it: the second run sent nothing from the same line.
     * A second run that is not whole in the trace says nothing.
     *
     * @param  array{effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, cut: bool}  $hit
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function neverSent(array $hit, int $place): array
    {
        $failed = $hit['effects'][$place] ?? null;
        $marker = array_find_key($hit['effects'], fn (array $effect, int $at) => $at > $place && ($effect['again'] ?? false));

        if ($failed === null || $marker === null || $hit['cut'] || ! ($failed['job'] ?? false)) {
            return [];
        }

        return self::same(self::ran($hit['effects'], $marker), $failed) === [] ? [$failed] : [];
    }

    /**
     * Get what a job sent more times than in the normal run when it was
     * tried again after a save or an email failed in it: the second run
     * sent again what the first run had sent. Only the count from one line
     * is compared, so a job that sends to many and goes on where it
     * stopped is clean. An email that failed did not leave, and is not
     * counted.
     *
     * @param  array{effects: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>, cut: bool}  $hit
     * @param  array{did: list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>}|null  $was
     * @return list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>
     */
    protected static function sentTwice(array $hit, int $place, ?array $was): array
    {
        $marker = array_find_key($hit['effects'], fn (array $effect, int $at) => $at > $place && ($effect['again'] ?? false));

        if ($was === null || $marker === null || $hit['cut']) {
            return [];
        }

        $sent = fn (array $effect, int $at = -1): bool => $at !== $place && ($effect['job'] ?? false) && ! ($effect['again'] ?? false) && in_array($effect['kind'], self::SENT, true) && ! self::takesTwice($effect);
        $normal = self::byName(array_values(array_filter($was['did'], $sent)));
        $both = self::byName(array_values(array_filter($hit['effects'], $sent, ARRAY_FILTER_USE_BOTH)));
        $first = self::byName(array_values(array_filter($hit['effects'], fn (array $effect, int $at) => $at < $marker && $sent($effect, $at), ARRAY_FILTER_USE_BOTH)));

        return array_values(array_map(
            fn (array $same) => $same[0],
            array_filter($first, fn (array $same, string $key) => count($both[$key]) > count($normal[$key] ?? []), ARRAY_FILTER_USE_BOTH),
        ));
    }

    /**
     * Determine if a thing a job did was done by a job inside that job: a
     * job of the framework that delivers one email, notification or
     * broadcast, or a job the job dispatched. The nearest job before it in
     * the trace is such a job. In use that job runs by itself, so its
     * failure is not the failure of the job around it.
     *
     * @param  list<array{kind: string, open: int, sql?: string, what?: string, at?: string|null, job?: bool, again?: bool, delivers?: bool, keyed?: bool}>  $effects
     */
    protected static function inside(array $effects, int $place): bool
    {
        for ($at = $place - 1; $at >= 0; $at--) {
            if ($effects[$at]['kind'] === 'job') {
                return ($effects[$at]['delivers'] ?? false) || ($effects[$at]['job'] ?? false);
            }
        }

        return false;
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
            // A call the service can take twice does no harm when it is made again.
            if (in_array($effect['kind'], self::SENT, true) && ! self::takesTwice($effect)) {
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
     * that is already there. An outside call the service can take twice
     * is not.
     *
     * @param  array{kind: string, sql?: string, what?: string, keyed?: bool}  $effect
     */
    protected static function repeats(array $effect, bool $stayed): bool
    {
        $sql = $effect['sql'] ?? '';

        return (in_array($effect['kind'], self::SENT, true) && ! self::takesTwice($effect))
            || ($stayed && AppTraces::verb($sql) === 'insert' && preg_match('/\bon\s+(conflict|duplicate\s+key)\b|^\s*insert\s+(or\s+)?ignore\b|^\s*insert\s+or\s+replace\b/i', $sql) !== 1);
    }

    /**
     * Determine if an outside call can be made again with no harm: the
     * service can take it twice. A GET, a PUT and a DELETE can, and so
     * can a call that says which call it is (an idempotency key).
     *
     * @param  array{kind: string, sql?: string, what?: string, keyed?: bool}  $effect
     */
    protected static function takesTwice(array $effect): bool
    {
        return $effect['kind'] === 'http' && (($effect['keyed'] ?? false) || preg_match('/^http (POST|PATCH) /', self::name($effect)) !== 1);
    }

    /**
     * Determine if a thing is a file the app deleted from a disk, or moved
     * on it: the file is not where it was.
     *
     * @param  array{kind: string, sql?: string, what?: string}  $effect
     */
    protected static function gone(array $effect): bool
    {
        return $effect['kind'] === 'file' && in_array($effect['what'] ?? null, ['delete', 'move'], true);
    }

    /**
     * Determine if a thing is one that can be made to fail: an email, an
     * outside call, or a file the app writes or moves. A delete is not.
     *
     * @param  array{kind: string, sql?: string, what?: string}  $effect
     */
    protected static function fails(array $effect): bool
    {
        return in_array($effect['kind'], self::FAILS, true) && ! ($effect['kind'] === 'file' && ($effect['what'] ?? null) === 'delete');
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
     * filter can take.
     *
     * Pest makes the method of a test from the sentence that names it, and
     * its filter reads the sentence, not the method. An underscore in the
     * method is a space or a sign of the sentence, and two are an
     * underscore or two signs. The filter takes any character there.
     */
    protected static function filter(?string $test): ?string
    {
        $name = (string) preg_replace('/ with data set .*$/s', '', (string) $test);
        $method = substr((string) strrchr($name, ':'), 1);

        if (preg_match('/^'.self::SENTENCE.'([\w\x80-\xff]+)$/', $method, $sentence) === 1) {
            $any = fn (array $signs): string => strlen($signs[0]) === 1 ? '.' : '.{'.intdiv(strlen($signs[0]) + 1, 2).','.strlen($signs[0]).'}';

            return '::'.preg_replace_callback('/_+|[\x80-\xff]/', $any, $sentence[1]).'(?: with data set |$)';
        }

        return preg_match('/^\w+$/', $method) === 1 ? $method : null;
    }
}
