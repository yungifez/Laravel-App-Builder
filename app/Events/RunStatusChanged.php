<?php

namespace App\Events;

use App\Enums\RunStatus;
use App\Models\Run;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A construction run moved to a new state. Sent once the move is saved.
 */
class RunStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(public Run $run, public RunStatus $from, public RunStatus $to) {}
}
