<?php

namespace Database\Factories;

use App\Enums\WorkspaceStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'driver' => 'fake',
            'driver_id' => 'fake-'.Str::random(8),
            'status' => WorkspaceStatus::Ready,
            'image' => 'php:8.4-cli',
            'cpus' => 2,
            'memory_mb' => 2048,
            'pids' => 512,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(4),
        ];
    }
}
