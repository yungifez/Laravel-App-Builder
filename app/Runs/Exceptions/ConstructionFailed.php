<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * The run cannot build the change, with a reason the owner can read.
 */
class ConstructionFailed extends RuntimeException
{
    //
}
