<?php

namespace App\Models\Concerns;

use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasTeams
{
    /**
     * Get the team the user is currently working in.
     *
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    /**
     * Get the teams the user belongs to.
     *
     * @return BelongsToMany<Team, $this, Membership, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * Determine if the user belongs to the given team.
     */
    public function belongsToTeam(Team $team): bool
    {
        return $this->teamRole($team) !== null;
    }

    /**
     * Get the user's role on the given team, or null when they are not a member.
     */
    public function teamRole(Team $team): ?TeamRole
    {
        return Membership::query()
            ->where('team_id', $team->id)
            ->where('user_id', $this->id)
            ->first()
            ?->role;
    }

    /**
     * Determine if the user's role on the given team grants the permission.
     */
    public function hasTeamPermission(Team $team, string $permission): bool
    {
        return $this->teamRole($team)?->hasPermission($permission) ?? false;
    }

    /**
     * Determine if the given team is the user's current team.
     */
    public function isCurrentTeam(Team $team): bool
    {
        return $this->current_team_id === $team->id;
    }
}
