<?php

namespace TraceRecorder;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PDOException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

/**
 * Records what one request does: its queries, its transactions and what it
 * queues and sends, in order, each with the line of the app's code it came
 * from. One line of JSON is written per request.
 *
 * It records no values (queries keep their placeholders) and no times, so
 * the same request on the same code gives the same trace.
 *
 * It only listens, with one exception. When TRACE_RECORDER_FAULT names one
 * thing of one request of one test (the place it has in a trace recorded
 * before), that thing fails the way it fails in use: the mail cannot be
 * sent, the outside call gets no answer, the write is refused. The trace
 * of that request then shows what the app left behind.
 */
class Recorder
{
    /**
     * The most things kept for one request, so one request cannot fill the file.
     */
    protected const KEPT = 400;

    /** @var array<string, mixed>|null */
    protected ?array $operation = null;

    /** @var array<string, int> Each connection's open transactions that are not the request's */
    protected array $baseline = [];

    protected ?string $test;

    /** @var array{request: int, effect: int, kind: string}|null The one thing to make fail, when this test is the one named */
    protected ?array $fault = null;

    /** How many requests this test has made. */
    protected int $requests = 0;

    /** How many jobs the sync queue is running now, one inside the other. */
    protected int $jobs = 0;

    protected string $base;

    public function __construct(protected Application $app, protected string $directory)
    {
        $this->base = rtrim($app->basePath(), '/').'/';
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
        $events = $this->app->make('events');

        $events->listen(QueryExecuted::class, fn (QueryExecuted $query) => $this->effect(
            ['kind' => 'query', 'sql' => $query->sql],
            fails: fn () => new QueryException($query->connectionName, $query->sql, [], new PDOException('SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout')),
        ));
        $events->listen(TransactionBeginning::class, function (TransactionBeginning $event) {
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
        $events->listen(JobQueued::class, fn (JobQueued $event) => $this->effect(['kind' => 'job', 'what' => is_object($event->job) ? $event->job::class : (string) $event->job]));
        $events->listen(JobProcessing::class, function (JobProcessing $event) {
            // The sync queue runs a job where it is dispatched, without queueing it.
            if ($event->connectionName === 'sync') {
                $this->effect(['kind' => 'job', 'what' => $event->job->resolveName()]);
                $this->jobs++;
            }
        });
        $events->listen([JobProcessed::class, JobExceptionOccurred::class], function (JobProcessed|JobExceptionOccurred $event) {
            if ($event->connectionName === 'sync') {
                $this->jobs = max(0, $this->jobs - 1);
            }
        });
        $events->listen(MessageSending::class, fn (MessageSending $event) => $this->effect(
            ['kind' => 'mail', 'what' => (string) ($event->data['__laravel_mailable'] ?? $event->data['__laravel_notification'] ?? 'message')],
            fails: fn () => new TransportException('Connection could not be established with the mail server.'),
        ));
        $events->listen(NotificationSending::class, fn (NotificationSending $event) => $this->effect(['kind' => 'notification', 'what' => $event->notification::class]));
        $events->listen(RequestSending::class, fn (RequestSending $event) => $this->effect(
            ['kind' => 'http', 'what' => $event->request->method().' '.parse_url($event->request->url(), PHP_URL_HOST)],
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

        $this->baseline = array_map(fn ($connection) => $connection->transactionLevel(), $this->app->make('db')->getConnections());
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

        $route = $request->route();
        $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 0;

        $this->operation['route'] = is_object($route) && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : null;
        $this->operation['status'] = $status;
        $this->operation['refused'] = $status >= 400 || $this->invalid($request);
        $this->operation['blind'] = $this->blind();
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

        $route = $request->route();
        $status = method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : (int) ($exception->status ?? 500);

        $this->operation['route'] = is_object($route) && method_exists($route, 'uri') ? '/'.ltrim($route->uri(), '/') : null;
        $this->operation['status'] = $status >= 400 ? $status : 500;
        $this->operation['refused'] = true;
        $this->operation['blind'] = $this->blind();

        $this->finish();
    }

    /**
     * Write the trace of the request, once.
     */
    public function finish(): void
    {
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
     * Add one thing the request did, with how many transactions of its own
     * were open around it and the line of the app's code it came from.
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
                $effect['at'] = $this->origin();
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
     * Find the line of the app's own code that caused what happens now,
     * or null when the framework or a package did it by itself.
     */
    protected function origin(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            $file = $frame['file'] ?? '';

            // Below this frame is whatever sent the request, such as a test.
            if (($frame['class'] ?? '') === Middleware::class) {
                return null;
            }

            // A middleware that passes the request on did not cause it, and
            // neither did this recorder, wherever its files are.
            if (str_ends_with($frame['class'] ?? '', '\\Pipeline') || str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (str_starts_with($file, $this->base) && ! str_starts_with($file, $this->base.'vendor/') && ! str_starts_with($file, $this->base.'storage/') && ! str_starts_with($file, $this->base.'public/')) {
                return substr($file, strlen($this->base)).':'.($frame['line'] ?? 0);
            }
        }

        return null;
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
     * Name what a test replaced with a fake. What goes through a fake is
     * not sent, so this recorder does not see it.
     *
     * @return list<string>
     */
    protected function blind(): array
    {
        $fakes = [
            'events' => ['events', EventFake::class],
            'mail' => ['mail.manager', MailFake::class],
            'queue' => ['queue', QueueFake::class],
            'jobs' => [Dispatcher::class, BusFake::class],
            'notifications' => [ChannelManager::class, NotificationFake::class],
        ];

        return array_keys(array_filter($fakes, fn (array $fake) => $this->app->resolved($fake[0]) && $this->app->make($fake[0]) instanceof $fake[1]));
    }

    /**
     * Read the one thing to make fail, when this test is the one it names.
     *
     * @return array{request: int, effect: int, kind: string}|null
     */
    protected function faultToCause(): ?array
    {
        $fault = json_decode((string) getenv('TRACE_RECORDER_FAULT'), true);

        if (! is_array($fault) || $this->test === null || ($fault['test'] ?? null) !== $this->test || ! is_int($fault['request'] ?? null) || ! is_int($fault['effect'] ?? null) || ! is_string($fault['kind'] ?? null)) {
            return null;
        }

        return ['request' => $fault['request'], 'effect' => $fault['effect'], 'kind' => $fault['kind']];
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
                return $object::class.'::'.(method_exists($object, 'nameWithDataSet') ? $object->nameWithDataSet() : $object->name());
            }
        }

        return null;
    }
}
