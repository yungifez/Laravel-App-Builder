<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Render pages without the Vite manifest, so the suite does not depend on
     * a prior `npm run build`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Set up the testing traits, refusing to continue unless the default
     * connection points at the disposable PostgreSQL test database.
     *
     * The guard lives here rather than in beforeRefreshingDatabase() because
     * test classes `use RefreshDatabase` directly, and that trait's own empty
     * hook would override one declared on this parent class.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $this->ensureDisposableTestDatabase();

        return parent::setUpTraits();
    }

    /**
     * Fail unless the given (or default) connection is pgsql and its database
     * name ends in "_test", so a refresh can never wipe a development database.
     *
     * @throws RuntimeException
     */
    protected function ensureDisposableTestDatabase(?string $connectionName = null): void
    {
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();
        $database = (string) $connection->getDatabaseName();

        if ($driver !== 'pgsql' || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against [%s] database [%s] on connection [%s]. '
                .'Tests must use a pgsql database whose name ends in "_test". '
                .'Run `php artisan config:clear` and check phpunit.xml.',
                $driver,
                $database,
                $connection->getName(),
            ));
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
