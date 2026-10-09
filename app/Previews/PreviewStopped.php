<?php

namespace App\Previews;

use RuntimeException;

/**
 * A preview was stopped while it started, because the owner opened a newer
 * copy or closed this one. Nothing failed, so the owner is told nothing.
 */
class PreviewStopped extends RuntimeException {}
