<?php

namespace TraceRecorder;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\Concerns\WithoutExceptionHandlingHandler;
use Throwable;

/**
 * Stands in for the handler a test puts in place of the app's handling of
 * errors. That handler drops each report(), so the recorder could not tell
 * an app that records a failure from one that hides it. The stand-in tells
 * the recorder of each report(), then does what the test's handler does.
 */
class SeenHandler implements ExceptionHandler, WithoutExceptionHandlingHandler
{
    public function __construct(protected ExceptionHandler $handler, protected Recorder $recorder) {}

    public function report(Throwable $e)
    {
        try {
            $this->recorder->reported();
        } catch (Throwable) {
            //
        }

        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e)
    {
        return $this->handler->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e)
    {
        $this->handler->renderForConsole($output, $e);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->handler->{$method}(...$arguments);
    }
}
