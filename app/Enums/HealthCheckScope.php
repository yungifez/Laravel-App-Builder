<?php

namespace App\Enums;

/**
 * How much of the app a health check runs: everything the owner asked for,
 * or only the package lookups the scheduler runs now and then.
 */
enum HealthCheckScope: string
{
    case Full = 'full';
    case Packages = 'packages';
}
