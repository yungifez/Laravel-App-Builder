<?php

namespace App\Enums;

/**
 * How much of the owner's attention a decision made for them needs. Code
 * decides it from what the decision touches, never the model. What must be
 * decided first stays the plan's one question and its gate.
 */
enum AssumptionLevel: string
{
    /** Worth a look: it touches something that matters, or cannot be undone. */
    case Glance = 'glance';

    /** Shown only to whoever looks for it. */
    case Quiet = 'quiet';
}
