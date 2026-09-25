<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * This seeder creates a user with a known password, so it only runs when
     * APP_ENV=local.
     *
     * @throws RuntimeException
     */
    public function run(): void
    {
        if (! App::isLocal()) {
            throw new RuntimeException('DatabaseSeeder only runs when APP_ENV=local.');
        }

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
