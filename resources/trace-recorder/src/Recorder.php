<?php

namespace TraceRecorder;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher as Events;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;
use WeakMap;

/**
 * Records what one request does: its queries, its transactions and what it
 * queues and sends, in order, each with the line of the app's code it came
 * from. One line of JSON is written per request.
 *
 * It records no values (queries keep their placeholders) and no times, so
 * the same request on the same code gives the same trace.
 *
 * A test can put a fake in place of the mail, the notifications, the queue
 * or the jobs. Nothing is sent through a fake, so the recorder stands in
 * for each of them (see Fakes) and still sees what the app tried to send.
 *
 * It only listens, with one exception. When TRACE_RECORDER_FAULT names one
 * thing of one request of one test (the place it has in a trace recorded
 * before), that thing fails the way it fails in use: the mail cannot be
 * sent, the outside call gets no answer, the write is refused before it
 * is made, the job runs a second time when it is done. The trace of that
 * request then shows what the app left behind.
 *
 * It can also name an event. The listeners Laravel found for that event by
 * itself then run in the reverse order for that one request.
 *
 * And it can name a job the sync queue runs. That job is then held back
 * until the response is made, the way a real queue runs it later.
 */
class Recorder
{
    /**
     * The most things kept for one request, so one request cannot fill the file.
     */
    protected const KEPT = 400;

    /**
     * The most of the app's own code named for one thing a request did.
     */
    protected const FRAMES = 6;

    /**
     * The framework's calls that say which part of a request runs:
     * asking if the person may, checking what they sent, the route's own
     * code, building the answer, a model's hooks, a listener, a job, and
     * the handling of an error. A call that is not here says nothing.
     */
    protected const PHASES = [
        'Illuminate\\Auth\\Access\\Gate::raw' => 'authorization',
        'Illuminate\\Foundation\\Http\\FormRequest::passesAuthorization' => 'authorization',
        'Illuminate\\Foundation\\Http\\FormRequest::validateResolved' => 'validation',
        'Illuminate\\Validation\\Validator::passes' => 'validation',
        'Illuminate\\View\\View::render' => 'rendering',
        'Illuminate\\Http\\Resources\\Json\\JsonResource::resolve' => 'rendering',
        'Inertia\\Response::toResponse' => 'rendering',
        'Illuminate\\Events\\Dispatcher::dispatch' => 'listener',
        'Illuminate\\Queue\\CallQueuedHandler::call' => 'job',
        'Illuminate\\Foundation\\Exceptions\\Handler::report' => 'error',
        'Illuminate\\Foundation\\Exceptions\\Handler::render' => 'error',
        'Illuminate\\Routing\\Route::run' => 'handling',
    ];

    /** @var array<string, mixed>|null */
    protected ?array $operation = null;

    /** @var array<string, int> Each connection's open transactions that are not the request's */
    protected array $baseline = [];

    protected ?string $test;

    /** The file the running test is written in, when it is known. */
    protected ?string $testFile = null;

    /** @var WeakMap<object, true> The connections that tell this recorder of a query before it runs */
    protected WeakMap $watched;

    /** @var array{request: int, effect: int, kind: string, what?: string}|null The one thing to make fail, when this test is the one named */
    protected ?array $fault = null;

    /** The dispatcher the app started with. */
    protected ?object $events = null;

    /** @var array<string, list<string>>|null Each event with two or more listeners Laravel found by itself, in the order the app has them */
    protected ?array $found = null;

    /** @var array<string, true> The events this recorder hears of */
    protected array $followed = [];

    /** @var array{0: string, 1: array<int, mixed>}|null The event whose listeners run in the reverse order now, and the order they had */
    protected ?array $turned = null;

    /** How many requests this test has made. */
    protected int $requests = 0;

    /** How many jobs the sync queue is running now, one inside the other. */
    protected int $jobs = 0;

    /** @var list<int|null> The place in the trace of each job the sync queue is running now */
    protected array $running = [];

    /** @var list<Closure(): mixed> The jobs held back until the response is made */
    protected array $held = [];

    /** Whether a job that was held back runs now. */
    protected bool $releasing = false;

    /** @var array<string, true> The fakes that took work of this request this recorder could not see */
    protected array $hidden = [];

    protected Fakes $fakes;

    protected string $base;

    public function __construct(protected Application $app, protected string $directory)
    {
        $this->base = rtrim($app->basePath(), '/').'/';
        $this->watched = new WeakMap;
        $this->fakes = new Fakes($app, $this);
        $this->test = $this->runningTest();
        $this->fault = $this->faultToCause();
    }

    /**
     * Listen for what the app does. The listeners sit on the dispatcher
     * the app started with, which the database connections keep when a
     * test fakes events.
     */
    public function listen(): void
    {
        $events = $this->events = $this->app->make('events');

        // A run about a job that waits needs a sync queue that can hold it back.
        if (($this->fault['kind'] ?? null) === 'later') {
            try {
                $this->app->make('queue')->addConnector('sync', fn () => LaterQueue::connector($this));
            } catch (Throwable) {
                //
            }
        }

        $events->listen(QueryExecuted::class, function (QueryExecuted $query) {
            $this->watch($query->connection);
            $this->effect(['kind' => 'query', 'sql' => $query->sql]);
        });
        $events->listen(TransactionBeginning::class, function (TransactionBeginning $event) {
            $this->watch($event->connection);

            // A test that wraps itself in a transaction can open it inside
            // the first request; it is not the request's.
            if ($this->operation !== null && $this->byTestTools()) {
                $this->baseline[$event->connectionName] = ($this->baseline[$event->connectionName] ?? 0) + 1;

                return;
            }

            $this->effect(['kind' => 'begin'], origin: false);
        });
        $events->listen(TransactionCommitted::class, fn () => $this->effect(['kind' => 'commit'], origin: false));
        $events->listen(TransactionRolledBack::class, fn () => $this->effect(['kind' => 'rollback'], origin: false));
        $events->listen(JobQueued::class, fn (JobQueued $event) => $this->effect(['kind' => 'job', 'what' => $this->jobName($event->job)]));
        $events->listen(JobProcessing::class, function (JobProcessing $event) {
            // The sync queue runs a job where it is dispatched, without queueing it.
            if ($event->connectionName === 'sync') {
                // A job that was held back is noted where it was dispatched.
                $noted = $this->releasing && $this->jobs === 0;
                $this->running[] = $this->operation === null || $noted ? null : count($this->operation['effects']);

                if (! $noted) {
                    $this->effect(['kind' => 'job', 'what' => $event->job->resolveName(), ...($this->delivers($event->job) ? ['delivers' => true] : [])]);
                }

                $this->jobs++;
            }
        });
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], function (JobProcessed|JobExceptionOccurred $event) {
            if ($event->connectionName === 'sync') {
                $this->jobs = max(0, $this->jobs - 1);
                $place = array_pop($this->running);

                if ($event instanceof JobProcessed) {
                    $this->again($event->job, $place);
                } else {
                    $this->retry($event->job, $place);
                }
            }
        });
        $events->listen(MessageSending::class, fn (MessageSending $event) => $this->mailed((string) ($event->data['__laravel_mailable'] ?? $event->data['__laravel_notification'] ?? 'message')));
        $events->listen(NotificationSending::class, fn (NotificationSending $event) => $this->effect(['kind' => 'notification', 'what' => $event->notification::class]));
        $events->listen(RequestSending::class, fn (RequestSending $event) => $this->effect(
            ['kind' => 'http', 'what' => $event->request->method().' '.parse_url($event->request->url(), PHP_URL_HOST), ...($this->keyed($event->request) ? ['keyed' => true] : [])],
            fails: fn () => new ConnectionException('cURL error 28: Operation timed out'),
        ));

        $this->app->terminating(fn () => $this->finish());
    }

    /**
     * Start the trace of a request.
     */
    public function start($request): void
    {
        $this->finish();
        $this->requests++;
        $this->jobs = 0;
        $this->running = [];
        $this->held = [];
        $this->hidden = [];
        $this->fakes->standIn();
        $this->follow();

        $connections = $this->app->make('db')->getConnections();
        array_map($this->watch(...), $connections);

        $this->baseline = array_map(fn ($connection) => $connection->transactionLevel(), $connections);
        $this->operation = [
            'test' => $this->test,
            'n' => $this->requests - 1,
            'method' => $request->method(),
            'route' => null,
            'status' => null,
            'refused' => false,
            'effects' => [],
            'blind' => [],
        ];
    }

    /**
     * Note how the request ended, before the work after the response.
     */
    public function respond($request, $response): void
    {
        if ($this->operation === null) {
            return;
        }

        $this->release();

        $route = $request->route();
        $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 0;

        $this->operation['route'] = is_object($route) && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : null;
        $this->operation['status'] = $status;
        $this->operation['refused'] = $status >= 400 || $this->invalid($request);
        $this->operation['blind'] = $this->fakes->hiding($this->hidden);
    }

    /**
     * Note that the request ended in an error nothing turned into a
     * response, and write its trace: nothing after the response will run.
     */
    public function fail($request, Throwable $exception): void
    {
        if ($this->operation === null) {
            return;
        }

        // The queue had the job before the error: it still runs.
        $this->release();

        $route = $request->route();
        $status = method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : (int) ($exception->status ?? 500);

        $this->operation['route'] = is_object($route) && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : null;
        $this->operation['status'] = $status >= 400 ? $status : 500;
        $this->operation['refused'] = true;
        $this->operation['blind'] = $this->fakes->hiding($this->hidden);

        $this->finish();
    }

    /**
     * Write the trace of the request, once.
     */
    public function finish(): void
    {
        $this->restore();

        if ($this->operation === null) {
            return;
        }

        $operation = $this->operation;
        $this->operation = null;

        if ($operation['status'] === null) {
            return;
        }

        try {
            file_put_contents(rtrim($this->directory, '/').'/trace.jsonl', json_encode($operation, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Note an email the app sends now. It is the one thing this run makes
     * fail when the run names it.
     */
    public function mailed(string $what): void
    {
        $this->effect(['kind' => 'mail', 'what' => $what], fails: fn () => new TransportException('Connection could not be established with the mail server.'));
    }

    /**
     * Note a job a test's fake took in place of the queue. A queue that
     * waits for the open transaction takes the job when that transaction
     * commits, so the job is noted then.
     */
    public function queued(mixed $job): void
    {
        if ($this->operation === null) {
            return;
        }

        // Noting a job never fails, so nothing of the app's is caught here.
        try {
            $note = fn () => $this->effect(['kind' => 'job', 'what' => $this->jobName($job)]);

            $this->waitsForCommit($job) && $this->app->bound('db.transactions')
                ? $this->app->make('db.transactions')->addCallback($note)
                : $note();
        } catch (Throwable) {
            //
        }
    }

    /**
     * Note a notification a test's fake took, as the app would have sent
     * it: on the queue, or now to each channel. Only the email channel is
     * known well enough to stand in for; another channel stays hidden.
     *
     * @param  array<array-key, mixed>  $channels
     */
    public function notified(object $notification, array $channels): void
    {
        if ($notification instanceof ShouldQueue) {
            $this->queued($notification);

            return;
        }

        foreach ($channels as $channel) {
            $this->effect(['kind' => 'notification', 'what' => $notification::class]);

            if ($channel === 'mail') {
                $this->mailed($notification::class);
            } elseif ($channel !== 'broadcast') {
                $this->hide('notifications');
            }
        }
    }

    /**
     * Note that a fake took work of this request that this recorder could
     * not see, such as a job the app runs before it answers.
     */
    public function hide(string $fake): void
    {
        if ($this->operation !== null) {
            $this->hidden[$fake] = true;
        }
    }

    /**
     * Add one thing the request did, with how many transactions of its own
     * were open around it and the code of the app it came from.
     * What a job on the sync queue does is marked: in use that job runs
     * later, on a queue.
     *
     * @param  array<string, mixed>  $effect
     * @param  (Closure(): Throwable)|null  $fails  How this thing fails in use, when it can be made to
     */
    protected function effect(array $effect, bool $origin = true, ?Closure $fails = null): void
    {
        if ($this->operation === null) {
            return;
        }

        $failing = false;

        try {
            if (count($this->operation['effects']) >= self::KEPT) {
                $this->operation['cut'] = true;

                return;
            }

            $effect['open'] = $this->open();

            if ($origin) {
                $effect = [...$effect, ...$this->cause()];
            }

            if ($this->jobs > 0) {
                $effect['job'] = true;
            }

            $place = count($this->operation['effects']);
            $failing = $fails !== null && $this->fault !== null && ! isset($this->operation['fault'])
                && $this->fault === ['request' => $this->requests - 1, 'effect' => $place, 'kind' => $effect['kind']];

            $this->operation['effects'][] = $effect;

            if ($failing) {
                $this->operation['fault'] = $place;
            }
        } catch (Throwable) {
            //
        }

        // The one failure this run is about: the thing just noted fails.
        if ($failing) {
            throw $fails();
        }
    }

    /**
     * Determine if an outside call says which call it is, so the service
     * that gets it twice can do it once: a header or a field whose name
     * says idempotency. Only the name is read, never the value.
     */
    protected function keyed(object $request): bool
    {
        try {
            $names = [...array_keys($request->headers()), ...array_keys((array) $request->data())];
        } catch (Throwable) {
            return false;
        }

        return array_any($names, fn ($name) => stripos((string) $name, 'idempoten') !== false || strcasecmp((string) $name, 'PayPal-Request-Id') === 0);
    }

    /**
     * Determine if a job is the framework's own: it delivers one email,
     * notification or broadcast, and has no code of the app to make safe.
     */
    protected function delivers(object $job): bool
    {
        try {
            $command = method_exists($job, 'payload') ? ($job->payload()['data']['commandName'] ?? null) : null;
        } catch (Throwable) {
            return false;
        }

        return in_array($command, [
            'Illuminate\\Notifications\\SendQueuedNotifications',
            'Illuminate\\Mail\\SendQueuedMailable',
            'Illuminate\\Broadcasting\\BroadcastEvent',
        ], true);
    }

    /**
     * Run a job a second time, when it is the job this run is about. A
     * queue gives a job to a worker at least once: a worker that stops
     * after the job's work and before it says so makes the job run again.
     * The second run is marked in the trace. An error in it stays in it,
     * because in use it happens on the queue and not in the request.
     */
    protected function again(object $job, ?int $place): void
    {
        if ($place === null || $this->operation === null || $this->jobs > 0 || isset($this->operation['fault'])
            || $this->fault !== ['request' => $this->requests - 1, 'effect' => $place, 'kind' => 'job']
            || ($this->operation['effects'][$place]['kind'] ?? null) !== 'job' || ! method_exists($job, 'fire')) {
            return;
        }

        $this->operation['fault'] = $place;
        $this->rerun($job, $place);
    }

    /**
     * Run a job a second time, when the write this run is about failed in
     * it and the job did not take the failure in. A queue tries a failed
     * job again: what the job did before the failure is then done again.
     */
    protected function retry(object $job, ?int $place): void
    {
        $failed = $this->operation['fault'] ?? null;

        if ($place === null || $this->operation === null || $this->jobs > 0 || ! is_int($failed) || $failed <= $place
            || ($this->fault['kind'] ?? null) !== 'query'
            || ! ($this->operation['effects'][$failed]['job'] ?? false)
            || ($this->operation['effects'][$place]['kind'] ?? null) !== 'job'
            || array_any($this->operation['effects'], fn (array $effect) => $effect['again'] ?? false)
            || ! method_exists($job, 'fire')) {
            return;
        }

        $this->rerun($job, $place);
    }

    /**
     * Run a job again and mark in the trace where its second run starts.
     */
    protected function rerun(object $job, int $place): void
    {
        $this->jobs++;
        $this->effect(['kind' => 'job', 'what' => $this->operation['effects'][$place]['what'] ?? 'job', 'again' => true], origin: false);

        try {
            $job->fire();
        } catch (Throwable) {
            //
        } finally {
            $this->jobs = max(0, $this->jobs - 1);
        }
    }

    /**
     * Hold back a job the sync queue is about to run, when it is the job
     * this run is about. In use the job waits on a queue and runs after
     * the response, so here it runs when the response is made. A job the
     * app sends to the sync queue by name runs in place in use too, and
     * is not held.
     *
     * @param  Closure(): object  $queued  Makes the job as the queue sees it
     * @param  Closure(): mixed  $run  Runs the job
     */
    public function hold(mixed $job, Closure $queued, Closure $run): bool
    {
        if ($this->operation === null || $this->releasing || $this->jobs > 0 || isset($this->operation['fault'])
            || $this->fault !== ['request' => $this->requests - 1, 'effect' => count($this->operation['effects']), 'kind' => 'later']
            || (is_object($job) && ($job->connection ?? null) !== null)) {
            return false;
        }

        $place = count($this->operation['effects']);
        $queued = $queued();
        $this->effect(['kind' => 'job', 'what' => $queued->resolveName(), ...($this->delivers($queued) ? ['delivers' => true] : []), 'later' => true]);

        if (count($this->operation['effects']) !== $place + 1) {
            return false;
        }

        $this->operation['fault'] = $place;
        $this->held[] = $run;

        return true;
    }

    /**
     * Run the jobs that were held back. An error in one stays in it,
     * because in use it happens on the queue and not in the request.
     */
    protected function release(): void
    {
        $held = $this->held;
        $this->held = [];
        $this->releasing = true;

        try {
            foreach ($held as $run) {
                try {
                    $run();
                } catch (Throwable) {
                    //
                }
            }
        } finally {
            $this->releasing = false;
        }
    }

    /**
     * Hear of each event that has two or more listeners Laravel found by
     * itself. Laravel takes those in the order the disk lists their files,
     * so their order is not the same on every machine. Listeners the app
     * registers by hand have the order its code gives them, and are left
     * alone. When this run names one of the events, its found listeners
     * run in the reverse order for this request.
     */
    protected function follow(): void
    {
        try {
            if ($this->events === null || ! method_exists($this->events, 'getRawListeners')) {
                return;
            }

            $this->found ??= $this->found();

            foreach (array_keys($this->found) as $event) {
                if (! isset($this->followed[$event])) {
                    $this->followed[$event] = true;
                    $this->events->listen($event, fn () => $this->dispatched($event));
                }
            }

            $event = $this->fault['what'] ?? null;

            if (($this->fault['kind'] ?? null) === 'event' && $this->fault['request'] === $this->requests - 1 && isset($this->found[$event])) {
                $this->turn($event);
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Find the events with two or more listeners Laravel found by itself
     * that the app still has, each with those listeners in the order the
     * app has them. Laravel names a listener it found as Class@method.
     *
     * @return array<string, list<string>>
     */
    protected function found(): array
    {
        $raw = $this->events->getRawListeners();
        $named = fn ($listeners) => array_filter((array) $listeners, fn ($listener) => is_string($listener) && str_contains($listener, '@'));

        // No event has two of them: the app's folders need not be read.
        if (! array_any($raw, fn ($listeners) => count($named($listeners)) >= 2)) {
            return [];
        }

        $discovered = [];

        foreach ($this->app->getProviders(EventServiceProvider::class) as $provider) {
            foreach ($provider->shouldDiscoverEvents() ? $provider->discoverEvents() : [] as $event => $listeners) {
                $discovered[$event] = [...($discovered[$event] ?? []), ...array_values((array) $listeners)];
            }
        }

        $found = [];

        foreach ($discovered as $event => $listeners) {
            $kept = array_values(array_filter($named($raw[$event] ?? []), fn ($listener) => in_array($listener, $listeners, true)));

            if (count($kept) >= 2) {
                $found[$event] = $kept;
            }
        }

        return $found;
    }

    /**
     * Put the found listeners of an event in the reverse order. Each
     * other listener keeps its place. A listener of this recorder goes
     * first: it says that the event came, also when a listener throws.
     */
    protected function turn(string $event): void
    {
        $raw = array_values($this->events->getRawListeners()[$event] ?? []);
        $places = array_keys(array_filter($raw, fn ($listener) => is_string($listener) && in_array($listener, $this->found[$event], true)));

        if (count($places) < 2) {
            return;
        }

        $turned = $raw;

        foreach ($places as $position => $place) {
            $turned[$place] = $raw[$places[count($places) - 1 - $position]];
        }

        $this->turned = [$event, $raw];
        $this->events->forget($event);
        $this->events->listen($event, fn () => $this->dispatched($event));
        array_map(fn ($listener) => $this->events->listen($event, $listener), $turned);
    }

    /**
     * Give the listeners of the turned event the order they had.
     */
    protected function restore(): void
    {
        if ($this->turned === null) {
            return;
        }

        [$event, $raw] = $this->turned;
        $this->turned = null;

        try {
            $this->events->forget($event);
            array_map(fn ($listener) => $this->events->listen($event, $listener), $raw);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Note an event the request dispatched, once, with the line of the
     * app's code that dispatched it and its found listeners as the
     * trace names code. When its listeners run in the reverse order now,
     * that is the one thing this run changes.
     */
    protected function dispatched(string $event): void
    {
        if ($this->operation === null) {
            return;
        }

        try {
            if (! in_array($event, array_column($this->operation['events'] ?? [], 'what'), true)) {
                $this->operation['events'][] = [
                    'what' => $event,
                    'at' => $this->cause()['at'],
                    'listeners' => array_map(fn (string $listener) => str_replace('@', '::', $listener), $this->found[$event] ?? []),
                ];
            }

            if (($this->turned[0] ?? null) === $event && ! isset($this->operation['fault'])) {
                $this->operation['fault'] = $this->fault['effect'];
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Ask a connection to tell this recorder of each query before it runs,
     * once. A write can then be refused before it is made, which is how a
     * database fails: nothing of the write stays.
     */
    protected function watch($connection): void
    {
        try {
            if (! is_object($connection) || isset($this->watched[$connection]) || ! method_exists($connection, 'beforeExecuting')) {
                return;
            }

            $this->watched[$connection] = true;
        } catch (Throwable) {
            return;
        }

        $connection->beforeExecuting(fn ($sql) => $this->refuse((string) $sql, $connection->getName()));
    }

    /**
     * Refuse the query that is about to run, when it is the write this run
     * is to make fail. A query that is not a write is never refused: the
     * test took another way this time.
     */
    protected function refuse(string $sql, ?string $connection): void
    {
        if ($this->operation === null || $this->fault === null || isset($this->operation['fault'])
            || $this->fault !== ['request' => $this->requests - 1, 'effect' => count($this->operation['effects']), 'kind' => 'query']
            || preg_match('/^[\s(]*(insert|update|delete|replace)\b/i', $sql) !== 1) {
            return;
        }

        $this->effect(
            ['kind' => 'query', 'sql' => $sql],
            fails: fn () => new QueryException((string) $connection, $sql, [], new PDOException('SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout')),
        );
    }

    /**
     * Count the transactions open now that the request itself opened. A
     * test that wraps each test in a transaction opened one that is not
     * the request's.
     */
    protected function open(): int
    {
        $open = 0;

        foreach ($this->app->make('db')->getConnections() as $name => $connection) {
            $open += max(0, $connection->transactionLevel() - ($this->baseline[$name] ?? 0));
        }

        return $open;
    }

    /**
     * Determine if the test's own tools, not the app, do what happens now:
     * a test that refreshes the database lazily opens its transaction at
     * the first query, inside the request. The search stops where the
     * request starts, because a test also sends the request.
     */
    protected function byTestTools(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            if (in_array($frame['class'] ?? '', [Middleware::class, Kernel::class], true)) {
                return false;
            }

            if (str_contains(str_replace('\\', '/', $frame['file'] ?? ''), '/Illuminate/Foundation/Testing/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find what caused the thing that happens now, from the calls that led
     * to it:
     *
     * - "at": the nearest line of the app's own code, or null when the
     *   framework or a package did it by itself. What the test's own code
     *   does inside a request, such as a second person saving at the same
     *   moment, is not the app's either.
     * - "frames": the app's own code on the way, nearest first, each as
     *   Class::method (or the file, for code outside a class).
     * - "phase": the part of the request it happened in (see PHASES).
     *
     * @return array{at: string|null, phase: string, frames?: list<string>}
     */
    protected function cause(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80);
        $at = null;
        $own = [];
        $phase = null;
        $nearest = true;
        $delivered = false;

        foreach ($frames as $position => $frame) {
            $class = $frame['class'] ?? '';
            $file = $frame['file'] ?? '';

            // Below this frame is whatever sent the request, such as a test.
            if ($class === Middleware::class || ($phase !== null && count($own) >= self::FRAMES)) {
                break;
            }

            // The first frames only bring the news to this recorder.
            $delivered = $delivered || ($class !== '' && $class !== Events::class && ! str_starts_with($class, __NAMESPACE__.'\\'));

            if ($delivered) {
                $phase ??= $this->phase($frames, $position);
            }

            // A middleware that passes the request on did not cause it, and
            // neither did this recorder, wherever its files are.
            if (str_ends_with($class, '\\Pipeline') || str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (str_starts_with($file, $this->base) && ! str_starts_with($file, $this->base.'vendor/') && ! str_starts_with($file, $this->base.'storage/') && ! str_starts_with($file, $this->base.'public/')) {
                // The frame after this one names the code this line is in.
                $in = $frames[$position + 1] ?? [];

                if ($file === $this->testFile || (($in['class'] ?? '') !== '' && is_a($in['class'], TestCase::class, true))) {
                    if ($nearest) {
                        return ['at' => null, 'phase' => 'unknown'];
                    }

                    break;
                }

                $name = $this->frameName($in, $file);

                if ($nearest) {
                    $at = substr($file, strlen($this->base)).':'.($frame['line'] ?? 0);
                    $nearest = false;
                }

                if (count($own) < self::FRAMES && end($own) !== $name) {
                    $own[] = $name;
                }
            }
        }

        return ['at' => $at, 'phase' => $phase ?? 'unknown', ...($own === [] ? [] : ['frames' => $own])];
    }

    /**
     * Name the part of a request a call belongs to, or null when the call
     * says nothing. The search goes from the thing that happened outward,
     * so the nearest part wins: a policy asked from a controller is
     * "authorization", not "handling".
     *
     * @param  list<array<string, mixed>>  $frames
     */
    protected function phase(array $frames, int $position): ?string
    {
        $frame = $frames[$position];
        $phase = self::PHASES[($frame['class'] ?? '').'::'.$frame['function']] ?? null;

        // A model tells its observers and hooks through the same events.
        if ($phase === 'listener') {
            foreach (array_slice($frames, $position + 1, 3) as $behind) {
                if (($behind['class'] ?? '').'::'.$behind['function'] === 'Illuminate\\Database\\Eloquent\\Model::fireModelEvent') {
                    return 'model';
                }
            }
        }

        // A pipeline runs each middleware by its "handle".
        if ($phase === null && $frame['function'] === 'handle' && str_ends_with(str_replace('\\', '/', $frame['file'] ?? ''), '/Illuminate/Pipeline/Pipeline.php')) {
            return 'middleware';
        }

        return $phase;
    }

    /**
     * Name the app's code a call was made in: its class and method. A
     * closure is named as the method it is written in when PHP says which,
     * and code outside a class is named by its file.
     *
     * @param  array<string, mixed>  $in  The frame of the code the call was made in
     */
    protected function frameName(array $in, string $file): string
    {
        $class = (string) ($in['class'] ?? '');
        $function = (string) ($in['function'] ?? '');

        if ($class === '' || str_starts_with($function, '{closure:'.$this->base)) {
            return substr($file, strlen($this->base));
        }

        if (str_contains($function, '{closure')) {
            $function = preg_match('/::(\w+)\(\):\d+\}/', $function, $found) === 1 ? $found[1] : '{closure}';
        }

        return $class.'::'.$function;
    }

    /**
     * Determine if the request was sent back with validation errors.
     * The session has aged its flashed keys by now, so they are the old ones.
     */
    protected function invalid($request): bool
    {
        if (! method_exists($request, 'hasSession') || ! $request->hasSession()) {
            return false;
        }

        $session = $request->session();

        return in_array('errors', [...(array) $session->get('_flash.old', []), ...(array) $session->get('_flash.new', [])], true);
    }

    /**
     * Name a job the way the sync queue names it when it runs.
     */
    protected function jobName(mixed $job): string
    {
        try {
            return match (true) {
                $job instanceof Closure => 'Closure',
                is_object($job) => method_exists($job, 'displayName') ? (string) $job->displayName() : $job::class,
                default => (string) $job,
            };
        } catch (Throwable) {
            return 'job';
        }
    }

    /**
     * Determine if the queue takes a job only when the open transaction
     * commits: the job says so, or the queue it goes to is set that way.
     */
    protected function waitsForCommit(mixed $job): bool
    {
        if (is_object($job) && ! $job instanceof Closure) {
            if ($job instanceof ShouldQueueAfterCommit) {
                return ($job->afterCommit ?? null) !== false;
            }

            if (isset($job->afterCommit)) {
                return (bool) $job->afterCommit;
            }
        }

        $config = $this->app->make('config');
        $connection = is_object($job) && ! $job instanceof Closure && is_string($job->connection ?? null) ? $job->connection : $config->get('queue.default');

        return (bool) $config->get("queue.connections.{$connection}.after_commit", false);
    }

    /**
     * Read the one thing to make fail, when this test is the one it names.
     *
     * @return array{request: int, effect: int, kind: string, what?: string}|null
     */
    protected function faultToCause(): ?array
    {
        $fault = json_decode((string) getenv('TRACE_RECORDER_FAULT'), true);

        if (! is_array($fault) || $this->test === null || ($fault['test'] ?? null) !== $this->test || ! is_int($fault['request'] ?? null) || ! is_int($fault['effect'] ?? null) || ! is_string($fault['kind'] ?? null)) {
            return null;
        }

        return [
            'request' => $fault['request'],
            'effect' => $fault['effect'],
            'kind' => $fault['kind'],
            // An event is named, because it has no place among the things a request did.
            ...($fault['kind'] === 'event' && is_string($fault['what'] ?? null) ? ['what' => $fault['what']] : []),
        ];
    }

    /**
     * Find the test that is running, when one is: the app is made inside it.
     */
    protected function runningTest(): ?string
    {
        if (! class_exists(TestCase::class, false)) {
            return null;
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            $object = $frame['object'] ?? null;

            if ($object instanceof TestCase) {
                $this->testFile = $this->fileOf($object);

                return $object::class.'::'.(method_exists($object, 'nameWithDataSet') ? $object->nameWithDataSet() : $object->name());
            }
        }

        return null;
    }

    /**
     * Find the file a test is written in. A test written as a function
     * has a class made for it, which keeps the name of its file.
     */
    protected function fileOf(TestCase $test): ?string
    {
        try {
            $class = new ReflectionObject($test);
            $file = $class->hasProperty('__filename') ? $class->getStaticPropertyValue('__filename') : $class->getFileName();

            return is_string($file) ? $file : null;
        } catch (Throwable) {
            return null;
        }
    }
}
