<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\WorkspaceFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceFile>
 */
class WorkspaceFileFactory extends Factory
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
            'path' => '.env',
            'contents' => 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n",
        ];
    }
}
