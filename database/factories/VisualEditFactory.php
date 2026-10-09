<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use App\Models\VisualEdit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisualEdit>
 */
class VisualEditFactory extends Factory
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
            'file' => 'resources/js/pages/Dashboard.vue',
            'line' => 10,
            'column' => 9,
            'tag' => 'div',
            'device' => 'base',
            'changes' => ['gap' => 24],
            'classes_before' => 'flex gap-4',
            'classes_after' => 'flex gap-6',
            'base_revision' => str_repeat('a', 40),
            'commit_sha' => str_repeat('b', 40),
        ];
    }
}
