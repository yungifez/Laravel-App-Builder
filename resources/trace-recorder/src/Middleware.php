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

        $response = $next($request);

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
