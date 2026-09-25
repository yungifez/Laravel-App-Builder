<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_refuses_to_run_outside_the_local_environment()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DatabaseSeeder only runs when APP_ENV=local.');

        try {
            $this->seed(DatabaseSeeder::class);
        } finally {
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_seeder_creates_the_local_test_user_in_the_local_environment()
    {
        $this->app['env'] = 'local';

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }
}
