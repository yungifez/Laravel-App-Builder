<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use App\Rules\ProjectSourcePath;

class CreateProject
{
    /**
     * Register a customer application for the owner, storing its source as an
     * absolute path so workers resolve it the same way wherever they run.
     */
    public function handle(User $owner, string $name, string $sourcePath): Project
    {
        return $owner->projects()->create([
            'name' => $name,
            'source_path' => ProjectSourcePath::resolve($sourcePath) ?? $sourcePath,
        ]);
    }
}
