<?php

namespace App\Projects\Exceptions;

use RuntimeException;

/**
 * A change could not be committed to, or undone in, a project's repository
 * because the project has changed in the same places since.
 */
class RepositoryConflict extends RuntimeException {}
