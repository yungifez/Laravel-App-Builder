<?php

namespace Database\Factories;

use App\Features\AppBoundaries;
use App\Models\FeatureRequest;
use App\Models\FindingProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FindingProposal>
 */
class FindingProposalFactory extends Factory
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
            'kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING,
            'identity' => AppBoundaries::CHANGED_WHILE_AUTHORIZING.'|App\Policies\PostPolicy::view|save',
            'reason' => 'The owner asked for every refused visit to be logged, and this is that log.',
        ];
    }
}
