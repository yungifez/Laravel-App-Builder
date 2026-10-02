<?php

namespace App\Projects\Exceptions;

use RuntimeException;

/**
 * A project's repository was made once and is gone now, for example after
 * a restore that left out the repositories. Making it again from the
 * source would quietly drop every change kept since, so the work stops.
 */
class RepositoryMissing extends RuntimeException
{
    public static function forProject(int $projectId): self
    {
        return new self(__('This is our fault: we cannot find the saved copy of your app, so we stopped before changing anything. Your app is safe, and we are looking into it.')." (project {$projectId})");
    }
}
