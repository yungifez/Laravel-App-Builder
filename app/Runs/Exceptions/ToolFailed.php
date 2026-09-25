<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * A permitted tool call could not complete, for example reading a missing file.
 */
class ToolFailed extends RuntimeException
{
    //
}
