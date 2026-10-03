<?php

namespace Database\Factories;

use App\Models\DeveloperApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeveloperApplication>
 */
class DeveloperApplicationFactory extends Factory
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
            'about' => fake()->paragraph(),
            'link' => fake()->url(),
            'approved_at' => null,
            'declined_at' => null,
            'decided_by' => null,
        ];
    }

    /**
     * Indicate that an operator approved the application.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'approved_at' => now(),
        ]);
    }
}
