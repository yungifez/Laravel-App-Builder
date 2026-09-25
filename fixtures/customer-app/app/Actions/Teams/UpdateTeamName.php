<?php

namespace App\Actions\Teams;

use App\Models\Team;

class UpdateTeamName
{
    /**
     * Rename the given team.
     */
    public function handle(Team $team, string $name): Team
    {
        $team->update(['name' => $name]);

        return $team;
    }
}
