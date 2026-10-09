<?php

namespace Database\Factories;

use App\Enums\ContextMode;
use App\Models\ContextTrial;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContextTrial>
 */
class ContextTrialFactory extends Factory
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
            'round' => 1,
            'prompt' => 'Let members book rooms.',
            'mode' => ContextMode::Selective,
            'feature_request_id' => fn (array $attributes) => FeatureRequest::factory()->state(['project_id' => $attributes['project_id']]),
            'outcome' => ContextTrial::OUTCOME_COMPLETED,
            'measures' => ['first_attempt_passed' => true, 'verified' => true, 'tokens' => 12000, 'cost_usd' => 0.4, 'unpriced_calls' => 0, 'tool_calls' => 20, 'minutes' => 6.0, 'repairs' => 0, 'unexpected_areas' => 0],
        ];
    }
}
