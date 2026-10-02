<?php

namespace App\Runs;

use App\Models\Run;

/**
 * The one change a worker's token opens, bound for the length of its
 * request. The worker names no run, project or path to data: this is its
 * whole scope (architecture §11, "Workers"). A token for a whole app opens
 * the oldest change waiting for the owner's tool, or none while nothing
 * waits.
 */
final readonly class WorkerTask
{
    public function __construct(public ?Run $run, public bool $wholeApp = false) {}
}
