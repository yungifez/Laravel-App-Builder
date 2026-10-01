<?php

namespace TraceRecorder;

use Closure;
use Throwable;

class Middleware
{
    public function __construct(private Recorder $recorder) {}

    /**
     * Record everything the request does, from the first middleware in to
     * the last one out.
     */
    public function handle($request, Closure $next)
    {
        try {
            $this->recorder->start($request);
        } catch (Throwable) {
            //
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // A test can turn off the app's error page. In use the person
            // gets that page, so the request ended in an error.
            try {
                $this->recorder->fail($request, $exception);
            } catch (Throwable) {
                //
            }

            throw $exception;
        }

        try {
            $this->recorder->respond($request, $response);
        } catch (Throwable) {
            //
        }

        return $response;
    }

    /**
     * Keep the trace once the work after the response is done too.
     */
    public function terminate($request, $response): void
    {
        try {
            $this->recorder->finish();
        } catch (Throwable) {
            //
        }
    }
}
