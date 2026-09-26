<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view the project and its feature requests.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    /**
     * Determine whether the user can request features for the project.
     */
    public function requestFeatures(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }

    /**
     * Determine whether the user can change the project itself: accept and
     * undo changes, edit its notes and deploy it.
     */
    public function update(User $user, Project $project): bool
    {
        return $project->user_id === $user->id;
    }
}
