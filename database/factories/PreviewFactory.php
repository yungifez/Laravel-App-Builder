<?php

namespace Database\Factories;

use App\Enums\PreviewStatus;
use App\Models\FeatureRequest;
use App\Models\Preview;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Preview>
 */
class PreviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feature_request_id' => FeatureRequest::factory()->generated(),
            'host' => 'p'.Str::lower(Str::random(24)),
            'status' => PreviewStatus::Starting,
            'expires_at' => now()->addHour(),
        ];
    }

    /**
     * Indicate that the preview is running.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PreviewStatus::Ready,
            'port' => 20001,
            'upstream_url' => 'http://127.0.0.1:20001',
            'ready_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
