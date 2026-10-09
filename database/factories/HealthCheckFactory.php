<?php

namespace Database\Factories;

use App\Enums\HealthCheckScope;
use App\Enums\HealthCheckStatus;
use App\Models\HealthCheck;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheck>
 */
class HealthCheckFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'commit_sha' => str_repeat('a', 40),
            'scope' => HealthCheckScope::Full,
            'status' => HealthCheckStatus::Queued,
        ];
    }
}
