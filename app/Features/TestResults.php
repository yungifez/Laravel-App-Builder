<?php

namespace App\Features;

/**
 * Reads a test run's output (`php artisan test`) for the tests that failed.
 */
class TestResults
{
    /**
     * Get the failing tests' names as the runner prints them, for example
     * "Tests\Feature\Teams\SwitchCurrentTeamTest > users cannot switch…".
     *
     * @return list<string>
     */
    public static function failing(string $output): array
    {
        preg_match_all('/^\s*FAILED\s+(.+?)\s*$/m', $output, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Get the files of the failing tests, for example
     * "tests/Feature/Teams/SwitchCurrentTeamTest.php".
     *
     * @return list<string>
     */
    public static function failingFiles(string $output): array
    {
        $files = [];

        foreach (self::failing($output) as $name) {
            $class = trim(explode(' > ', $name, 2)[0]);

            if (preg_match('/^Tests\\\\[A-Za-z0-9_\\\\]+$/', $class) === 1) {
                $files[] = 'tests/'.str_replace('\\', '/', substr($class, strlen('Tests\\'))).'.php';
            }
        }

        return array_values(array_unique($files));
    }
}
