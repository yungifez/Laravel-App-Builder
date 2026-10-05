<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectNoteRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectNoteRevision>
 */
class ProjectNoteRevisionFactory extends Factory
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
            'branch' => 'main',
            'path' => 'project.md',
            'contents' => "# Project\n\n".fake()->sentence()."\n",
        ];
    }
}
