<?php

namespace App\Previews;

use RuntimeException;

/**
 * A preview stopped at a known point. The message's first line is for the
 * owner (see PreviewFailure); the lines after it are for operators.
 */
class PreviewCouldNotStart extends RuntimeException {}
