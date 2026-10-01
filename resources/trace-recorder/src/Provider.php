<?php

namespace TraceRecorder;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Throwable;

class Provider extends ServiceProvider
{
    /**
     * Start recording. A recorder that cannot start records nothing; it
     * never stops the app.
     */
    public function boot(): void
    {
        try {
            $recorder = new Recorder($this->app, (string) getenv('TRACE_RECORDER_DIR'));
            $this->app->instance(Recorder::class, $recorder);
            $recorder->listen();

            $kernel = $this->app->make(Kernel::class);

            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(Middleware::class);
            }
        } catch (Throwable) {
            //
        }
    }
}
