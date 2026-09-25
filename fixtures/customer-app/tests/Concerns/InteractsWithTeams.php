<?php

namespace Tests\Concerns;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;

trait InteractsWithTeams
{
    /**
     * Create a team whose owner is also working in it.
     */
    protected function createTeamOwnedBy(User $owner, string $name = 'Acme'): Team
    {
        return Team::factory()->ownedBy($owner)->create(['name' => $name]);
    }

    /**
     * Create a user with a personal team and add them to the team with the given role.
     */
    protected function addTeamMember(Team $team, TeamRole $role, array $attributes = []): User
    {
        $user = User::factory()->withPersonalTeam()->create($attributes);

        $team->members()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
