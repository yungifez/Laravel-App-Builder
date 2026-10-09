<?php

namespace Database\Factories;

use App\Features\AppBoundaries;
use App\Models\AcceptedFinding;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcceptedFinding>
 */
class AcceptedFindingFactory extends Factory
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
            'user_id' => User::factory(),
            'kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING,
            'identity' => AppBoundaries::CHANGED_WHILE_AUTHORIZING.'|App\Policies\PostPolicy::view|save',
        ];
    }
}
