<?php

namespace Database\Factories;

use App\Models\DeveloperReview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeveloperReview>
 */
class DeveloperReviewFactory extends Factory
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
            'user_id' => User::factory(),
            'question' => 'Is the way bookings are stored still sound?',
            'bundle' => "# Review request\n\n## What the owner wants help with\n\nIs the way bookings are stored still sound?\n",
        ];
    }
}
