<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feature_request_id' => FeatureRequest::factory(),
            'driver' => 'scripted',
            'status' => RunStatus::Queued,
        ];
    }

    /**
     * Indicate that the run is implementing and started now.
     */
    public function implementing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RunStatus::Implementing,
            'started_at' => now(),
        ]);
    }
}
