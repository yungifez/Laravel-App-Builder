<?php

namespace App\Runs\Exceptions;

use RuntimeException;

/**
 * The change is built by a worker outside our boxes, and it has not handed
 * the change back yet. The run waits; submit_change picks it up again.
 */
class WaitingForWorker extends RuntimeException {}
