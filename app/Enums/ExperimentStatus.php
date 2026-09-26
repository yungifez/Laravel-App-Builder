<?php

namespace App\Enums;

/**
 * Where an idea the owner is trying stands: still being tried on its own
 * branch, used in the app (merged into the main branch), or thrown away.
 */
enum ExperimentStatus: string
{
    case Open = 'open';
    case Merged = 'merged';
    case Discarded = 'discarded';
}
