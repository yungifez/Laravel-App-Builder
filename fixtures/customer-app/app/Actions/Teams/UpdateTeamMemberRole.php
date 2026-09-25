<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpdateTeamMemberRole
{
    /**
     * Change a team member's role.
     *
     * @throws ValidationException
     */
    public function handle(Team $team, User $member, TeamRole $role): void
    {
        if ($member->teamRole($team) === TeamRole::Owner) {
            throw ValidationException::withMessages([
                'role' => __('The team owner\'s role cannot be changed.'),
            ]);
        }

        if (! in_array($role, TeamRole::assignable(), true)) {
            throw ValidationException::withMessages([
                'role' => __('The selected role cannot be assigned.'),
            ]);
        }

        $team->members()->updateExistingPivot($member->id, ['role' => $role->value]);
    }
}
