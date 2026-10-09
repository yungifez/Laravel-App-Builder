<?php

namespace Database\Factories;

use App\Enums\ExperimentStatus;
use App\Models\Experiment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Experiment>
 */
class ExperimentFactory extends Factory
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
            'user_id' => fn (array $attributes) => Project::query()->whereKey($attributes['project_id'])->value('user_id'),
            'name' => 'A bigger booking form',
            'branch' => 'ideas/'.fake()->unique()->numberBetween(1, 1_000_000),
            'base_sha' => str_repeat('a', 40),
            'status' => ExperimentStatus::Open,
        ];
    }
}
