<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTeams;
use Tests\TestCase;

class TeamMemberRoleTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_admins_can_change_a_members_role()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());
        $admin = $this->addTeamMember($team, TeamRole::Admin);
        $member = $this->addTeamMember($team, TeamRole::Member);

        $this->actingAs($admin)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'admin'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertSame(TeamRole::Admin, $member->teamRole($team));
    }

    public function test_the_owners_role_cannot_be_changed()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);
        $admin = $this->addTeamMember($team, TeamRole::Admin);

        $this->actingAs($admin)
            ->put(route('team-members.update', [$team, $owner]), ['role' => 'member'])
            ->assertSessionHasErrors('role');

        $this->assertSame(TeamRole::Owner, $owner->teamRole($team));
    }

    public function test_the_owner_role_cannot_be_assigned()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);
        $member = $this->addTeamMember($team, TeamRole::Member);

        $this->actingAs($owner)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'owner'])
            ->assertSessionHasErrors('role');

        $this->assertSame(TeamRole::Member, $member->teamRole($team));
    }

    public function test_members_cannot_change_roles()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());
        $member = $this->addTeamMember($team, TeamRole::Member);
        $other = $this->addTeamMember($team, TeamRole::Member);

        $this->actingAs($member)
            ->put(route('team-members.update', [$team, $other]), ['role' => 'admin'])
            ->assertForbidden();
    }

    public function test_users_outside_the_team_cannot_be_targeted()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner)
            ->put(route('team-members.update', [$team, $outsider]), ['role' => 'admin'])
            ->assertNotFound();
    }
}
