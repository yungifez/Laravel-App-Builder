<?php

namespace Database\Factories;

use App\Models\Decision;
use App\Models\FeatureRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Decision>
 */
class DecisionFactory extends Factory
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
            'name' => 'question',
            'driver' => 'typesafe',
            'model' => null,
            'choice' => 'no',
            'probabilities' => ['yes' => 0.05, 'no' => 0.95],
            'confidence' => 0.95,
            'threshold' => 0.9,
            'acted' => false,
            'latency_ms' => 250,
        ];
    }
}
