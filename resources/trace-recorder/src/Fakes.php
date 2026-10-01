<?php

namespace TraceRecorder;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Support\Testing\Fakes\QueueFake;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The fakes a test puts in place of what the app sends with. Nothing goes
 * out through a fake, so the recorder would not see what the app tried to
 * send, and could not make it fail.
 *
 * For the mail, the notifications, the queue and the jobs, the recorder
 * puts a stand-in where the fake is. The stand-in is the same fake with
 * the same memory: it tells the recorder of each thing the app sends, then
 * does what the fake does. What the test asserts stays true.
 *
 * A fake this recorder does not know well enough is left as it is, and is
 * named as hiding what the request sent.
 */
class Fakes
{
    /**
     * Each fake by its name in a trace: where the app keeps it, its class,
     * its stand-in, and the methods the stand-in changes or needs.
     */
    protected const KNOWN = [
        'events' => ['events', EventFake::class, null, []],
        'mail' => ['mail.manager', MailFake::class, SeenMail::class, ['send', 'sendNow', 'queue']],
        'queue' => ['queue', QueueFake::class, SeenQueue::class, ['push', 'shouldFakeJob']],
        'jobs' => [Dispatcher::class, BusFake::class, SeenJobs::class, ['dispatch', 'dispatchSync', 'dispatchNow', 'dispatchToQueue', 'dispatchAfterResponse', 'chain', 'batch', 'shouldFakeJob']],
        'notifications' => [ChannelManager::class, NotificationFake::class, SeenNotifications::class, ['sendNow']],
    ];

    /** @var array<string, bool> Whether each stand-in fits the fake this app's framework has */
    protected array $fits = [];

    public function __construct(protected Application $app, protected Recorder $recorder) {}

    /**
     * Put a stand-in where each fake is that has none yet. It never stops
     * the app: a fake that cannot have one stays as it is.
     */
    public function standIn(): void
    {
        foreach (self::KNOWN as [$abstract, $fake, $standIn, $methods]) {
            try {
                $current = $standIn !== null && $this->app->resolved($abstract) ? $this->app->make($abstract) : null;

                // Only the framework's own fake is known; a class made from it is not.
                if (! is_object($current) || $current::class !== $fake || ! $this->fits($fake, $standIn, $methods)) {
                    continue;
                }

                $this->app->instance($abstract, $this->seen($current, $standIn));
                Facade::clearResolvedInstance($abstract);
            } catch (Throwable) {
                //
            }
        }
    }

    /**
     * Name each fake that hides what the request sent: one with no
     * stand-in, and one that took work its stand-in could not see.
     *
     * @param  array<string, true>  $hidden
     * @return list<string>
     */
    public function hiding(array $hidden): array
    {
        $names = [];

        foreach (self::KNOWN as $name => [$abstract, $fake, $standIn]) {
            $current = $this->app->resolved($abstract) ? $this->app->make($abstract) : null;

            if (isset($hidden[$name]) || ($current instanceof $fake && ($standIn === null || ! $current instanceof $standIn))) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Make the stand-in of a fake. Each thing the fake remembers is shared
     * between the two, so a test that kept the fake still reads it there.
     */
    protected function seen(object $fake, string $standIn): object
    {
        $seen = (new ReflectionClass($standIn))->newInstanceWithoutConstructor();

        Closure::bind(function (object $fake, object $seen): void {
            foreach (array_keys(get_object_vars($fake)) as $name) {
                $seen->{$name} = &$fake->{$name};
            }
        }, null, $fake::class)($fake, $seen);

        $seen->traceRecorder = $this->recorder;

        return $seen;
    }

    /**
     * Determine if a stand-in can be made from the fake this app's
     * framework has. A stand-in that does not fit its fake stops PHP when
     * its file is read, so the fake is looked at first. Each stand-in
     * takes any arguments; only a fake that says what its method returns,
     * or closes it, does not fit.
     *
     * @param  list<string>  $methods
     */
    protected function fits(string $fake, string $standIn, array $methods): bool
    {
        return $this->fits[$fake] ??= (function () use ($fake, $standIn, $methods): bool {
            if ((new ReflectionClass($fake))->isFinal()) {
                return false;
            }

            foreach ($methods as $name) {
                $method = method_exists($fake, $name) ? new ReflectionMethod($fake, $name) : null;

                if ($method === null || $method->isFinal() || $method->isStatic() || $method->isPrivate() || $method->hasReturnType()
                    || array_filter($method->getParameters(), fn ($parameter) => $parameter->isPassedByReference()) !== []) {
                    return false;
                }
            }

            return class_exists($standIn);
        })();
    }
}
