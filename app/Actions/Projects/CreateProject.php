<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;

class CreateProject
{
    /**
     * Register a customer application for the owner.
     */
    public function handle(User $owner, string $name, string $sourcePath): Project
    {
        return $owner->projects()->create([
            'name' => $name,
            'source_path' => $sourcePath,
        ]);
    }
}
