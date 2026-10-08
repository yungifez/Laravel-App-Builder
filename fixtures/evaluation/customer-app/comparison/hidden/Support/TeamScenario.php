<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Support;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for the hidden comparison suites.
 *
 * They build state only with the starter's own models and factories and read
 * it back from the starter's tables, so a generated change cannot alter what
 * the assertions mean. Roles are plain strings as stored in `team_user.role`.
 */
trait TeamScenario
{
    /**
     * Create a verified user who owns a personal team and is working in it.
     */
    protected function userWithPersonalTeam(array $attributes = []): User
    {
        return User::factory()->withPersonalTeam()->create($attributes);
    }

    /**
     * Create a shared (non-personal) team owned by the user and make it their current team.
     */
    protected function sharedTeamOwnedBy(User $owner, string $name = 'Acme'): Team
    {
        return Team::factory()->ownedBy($owner)->create(['name' => $name]);
    }

    /**
     * Create a user with a personal team and add them to the team with the given role.
     */
    protected function addToTeam(Team $team, string $role, array $attributes = []): User
    {
        $user = $this->userWithPersonalTeam($attributes);

        $team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /**
     * Add an existing user to the team with the given role.
     */
    protected function attachToTeam(Team $team, User $user, string $role): void
    {
        $team->members()->attach($user, ['role' => $role]);
    }

    /**
     * The user's stored role on the team, or null when they are not in it.
     */
    protected function roleOn(Team $team, User $user): ?string
    {
        $role = DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->value('role');

        return $role === null ? null : (string) $role;
    }

    protected function isInTeam(Team $team, User $user): bool
    {
        return $this->roleOn($team, $user) !== null;
    }

    /**
     * Number of people holding the owner role on the team.
     */
    protected function ownerCount(Team $team): int
    {
        return DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('role', 'owner')
            ->count();
    }

    protected function teamName(Team $team): ?string
    {
        $name = DB::table('teams')->where('id', $team->id)->value('name');

        return $name === null ? null : (string) $name;
    }

    protected function currentTeamIdOf(User $user): ?int
    {
        $id = DB::table('users')->where('id', $user->id)->value('current_team_id');

        return $id === null ? null : (int) $id;
    }

    protected function makeCurrentTeam(User $user, Team $team): void
    {
        $user->forceFill(['current_team_id' => $team->id])->save();
    }

    protected function personalTeamOf(User $user): Team
    {
        return $user->teams()->where('personal_team', true)->wherePivot('role', 'owner')->firstOrFail();
    }
}
