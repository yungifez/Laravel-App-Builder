<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $owner = User::factory()->withPersonalTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $team = Team::factory()->ownedBy($owner)->create(['name' => 'Acme']);

        $team->members()->attach(
            User::factory()->withPersonalTeam()->create(['name' => 'Admin User', 'email' => 'admin@example.com']),
            ['role' => TeamRole::Admin->value],
        );

        $team->members()->attach(
            User::factory()->withPersonalTeam()->create(['name' => 'Member User', 'email' => 'member@example.com']),
            ['role' => TeamRole::Member->value],
        );
    }
}
