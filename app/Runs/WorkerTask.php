<?php

namespace App\Runs;

use App\Models\Run;

/**
 * The one change a worker's token opens, bound for the length of its
 * request. The worker names no run, project or path to data: this is its
 * whole scope (architecture §11, "Workers").
 */
final readonly class WorkerTask
{
    public function __construct(public Run $run) {}
}
