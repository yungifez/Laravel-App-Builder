<?php

namespace App\Actions\Teams;

use App\Models\Team;
use App\Models\User;

class SwitchCurrentTeam
{
    /**
     * Make the given team the user's current team.
     */
    public function handle(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
    }
}
