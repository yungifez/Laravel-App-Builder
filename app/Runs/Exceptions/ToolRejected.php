<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * The server refused a tool call, for example a write to a protected path or
 * an edit based on stale file contents. Nothing in the workspace changed.
 */
class ToolRejected extends RuntimeException
{
    //
}
