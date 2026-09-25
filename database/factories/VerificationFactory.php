<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Verification>
 */
class VerificationFactory extends Factory
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
            'status' => VerificationStatus::Queued,
        ];
    }
}
