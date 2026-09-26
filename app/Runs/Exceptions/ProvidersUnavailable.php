<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * No configured AI provider could serve the task, so the run stops for the
 * owner rather than failing.
 */
class ProvidersUnavailable extends RuntimeException
{
    //
}
