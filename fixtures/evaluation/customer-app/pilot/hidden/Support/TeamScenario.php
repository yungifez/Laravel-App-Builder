<?php

namespace Tests\Hidden\Support;

use App\Models\Team;
use App\Models\User;

/**
 * Helpers for the hidden tests. They use only the starter's models and
 * factories, never code a change adds or the app's own test helpers, so a
 * change cannot alter what these tests mean.
 */
trait TeamScenario
{
    /**
     * Create a team (not a personal team) owned by a new user who also has a
     * personal team.
     *
     * @return array{0: Team, 1: User}
     */
    protected function teamWithOwner(string $name = 'Acme'): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->ownedBy($owner)->create(['name' => $name]);

        return [$team, $owner];
    }

    /**
     * Create a user with a personal team and add them to the team with the role.
     */
    protected function member(Team $team, string $role = 'member'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * Get the user's personal team.
     */
    protected function personalTeamOf(User $user): Team
    {
        return $user->teams()->where('personal_team', true)->firstOrFail();
    }

    /**
     * Make the team the user's current team.
     */
    protected function workIn(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
    }

    /**
     * Determine if the user is in the team, read straight from the memberships.
     */
    protected function isIn(User $user, Team $team): bool
    {
        return $team->members()->whereKey($user->id)->exists();
    }
}
