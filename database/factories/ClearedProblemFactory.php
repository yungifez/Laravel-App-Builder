<?php

namespace Database\Factories;

use App\Models\ClearedProblem;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClearedProblem>
 */
class ClearedProblemFactory extends Factory
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
            'problem' => sha1(fake()->unique()->sentence()),
            'cleared_at' => now(),
        ];
    }
}
