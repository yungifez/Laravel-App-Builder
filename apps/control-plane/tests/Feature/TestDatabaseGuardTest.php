<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_suite_runs_against_the_disposable_pgsql_test_database()
    {
        $connection = DB::connection();

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertStringEndsWith('_test', (string) $connection->getDatabaseName());
        $this->assertStringEndsWith('_test', (string) $connection->scalar('select current_database()'));
    }

    public function test_guard_rejects_a_database_without_the_test_suffix()
    {
        config([
            'database.connections.development_like' => [
                ...config('database.connections.pgsql'),
                'database' => 'control_plane',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests against [pgsql] database [control_plane]');

        $this->ensureDisposableTestDatabase('development_like');
    }

    public function test_guard_rejects_a_non_pgsql_connection()
    {
        config([
            'database.connections.sqlite_test' => [
                'driver' => 'sqlite',
                'database' => 'control_plane_test',
                'prefix' => '',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to run tests against [sqlite]');

        $this->ensureDisposableTestDatabase('sqlite_test');
    }
}
