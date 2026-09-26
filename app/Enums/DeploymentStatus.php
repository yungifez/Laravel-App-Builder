<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Checking = 'checking';
    case Pushing = 'pushing';
    case Published = 'published';
    case Failed = 'failed';

    /**
     * Determine if the deployment is still in progress.
     */
    public function active(): bool
    {
        return in_array($this, [self::Checking, self::Pushing], true);
    }
}
