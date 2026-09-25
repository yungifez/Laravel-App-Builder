<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    /**
     * Create a team owned by the given user.
     *
     * The team becomes the owner's current team when they do not have one yet.
     */
    public function handle(User $owner, string $name, bool $personal = false): Team
    {
        return DB::transaction(function () use ($owner, $name, $personal) {
            $team = Team::create([
                'name' => $name,
                'personal_team' => $personal,
            ]);

            $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

            if ($owner->current_team_id === null) {
                $owner->forceFill(['current_team_id' => $team->id])->save();
            }

            return $team;
        });
    }
}
