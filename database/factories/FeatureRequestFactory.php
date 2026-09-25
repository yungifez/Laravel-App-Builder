<?php

namespace Database\Factories;

use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureRequest>
 */
class FeatureRequestFactory extends Factory
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
            'prompt' => 'Let team owners invite people by email.',
            'status' => FeatureRequestStatus::Generating,
            'generator' => 'reference',
        ];
    }

    /**
     * Indicate that the change was generated with a permission step.
     */
    public function generated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FeatureRequestStatus::Generated,
            'solution_key' => 'team-invitations',
            'summary' => 'Invitations.',
            'patch' => "diff --git a/app/A.php b/app/A.php\n--- a/app/A.php\n+++ b/app/A.php\n@@ -1 +1,2 @@\n <?php\n+// added\n",
            'steps' => [[
                'key' => 'permission',
                'kind' => 'permission',
                'label' => 'Who may invite people',
                'file' => 'app/Policies/TeamPolicy.php',
                'symbol' => 'TeamPolicy::inviteMember',
                'detail' => 'Checks members:invite.',
            ]],
        ]);
    }
}
