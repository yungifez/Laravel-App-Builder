<?php

namespace App\Runs\Exceptions;

use App\Enums\RunStatus;
use LogicException;

class InvalidRunTransition extends LogicException
{
    /**
     * Create an exception for a transition the state machine does not allow.
     */
    public static function between(RunStatus $from, RunStatus $to): self
    {
        return new self("A run cannot move from [{$from->value}] to [{$to->value}].");
    }
}
