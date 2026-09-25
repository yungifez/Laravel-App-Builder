<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    /**
     * Determine whether the user can view the team.
     */
    public function view(User $user, Team $team): bool
    {
        return $user->belongsToTeam($team);
    }

    /**
     * Determine whether the user can update the team's details.
     */
    public function update(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, 'team:update');
    }

    /**
     * Determine whether the user can change the roles of the team's members.
     */
    public function updateMemberRole(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, 'members:update-role');
    }

    /**
     * Determine whether the user can remove members from the team.
     */
    public function removeMember(User $user, Team $team): bool
    {
        return $user->hasTeamPermission($team, 'members:remove');
    }
}
