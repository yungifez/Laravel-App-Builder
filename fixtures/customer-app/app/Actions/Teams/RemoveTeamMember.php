<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveTeamMember
{
    /**
     * Remove a member from the team.
     *
     * A removed member who was working in the team is moved to another team
     * they belong to, preferring their personal team.
     *
     * @throws ValidationException
     */
    public function handle(Team $team, User $member): void
    {
        if ($member->teamRole($team) === TeamRole::Owner) {
            throw ValidationException::withMessages([
                'member' => __('The team owner cannot be removed.'),
            ]);
        }

        DB::transaction(function () use ($team, $member) {
            $team->members()->detach($member->id);

            if ($member->isCurrentTeam($team)) {
                $fallbackTeam = $member->teams()
                    ->orderByDesc('personal_team')
                    ->orderBy('teams.id')
                    ->first();

                $member->forceFill(['current_team_id' => $fallbackTeam?->id])->save();
            }
        });
    }
}
