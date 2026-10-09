<?php

namespace App\Workspaces\Drivers;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Throwable;

trait WaitsWhileRunning
{
    /**
     * Wait for a started process, calling "whileRunning" several times a second.
     * When it throws, "stop" ends the process before the exception is
     * passed on, so nothing keeps working for a caller that has given up.
     *
     * @param  Closure(): void  $whileRunning
     * @param  Closure(): void  $stop
     */
    protected function waitWhileRunning(InvokedProcess $process, Closure $whileRunning, Closure $stop): ProcessResult
    {
        while ($process->running()) {
            try {
                $whileRunning();
            } catch (Throwable $exception) {
                $stop();

                throw $exception;
            }

            usleep(250_000);
        }

        return $process->wait();
    }
}
