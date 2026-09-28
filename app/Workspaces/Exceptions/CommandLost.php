<?php

namespace App\Workspaces\Exceptions;

use RuntimeException;

/**
 * No runner took a workspace command, or it never answered. The command did
 * not run to an end, so nothing can be said about the code it ran on.
 */
class CommandLost extends RuntimeException {}
