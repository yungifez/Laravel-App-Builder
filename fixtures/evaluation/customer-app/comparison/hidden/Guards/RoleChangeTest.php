<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: owners and admins change other people's roles to admin or member.
 *
 * Beyond the app's own suite: owners promoting and demoting, admins demoting
 * other admins, people from another team, invalid roles, and the permission
 * following config/teams.php.
 */
class RoleChangeTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_owners_can_promote_a_member_and_demote_an_admin()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');
        $admin = $this->addToTeam($team, 'admin');

        $this->actingAs($owner)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'admin'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->actingAs($owner)
            ->put(route('team-members.update', [$team, $admin]), ['role' => 'member'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertSame('admin', $this->roleOn($team, $member));
        $this->assertSame('member', $this->roleOn($team, $admin));
        $this->assertSame('owner', $this->roleOn($team, $owner));
    }

    public function test_admins_can_demote_another_admin()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $admin = $this->addToTeam($team, 'admin');
        $otherAdmin = $this->addToTeam($team, 'admin');

        $this->actingAs($admin)
            ->put(route('team-members.update', [$team, $otherAdmin]), ['role' => 'member'])
            ->assertSessionHasNoErrors();

        $this->assertSame('member', $this->roleOn($team, $otherAdmin));
    }

    public function test_changing_a_role_only_affects_that_team()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $otherTeam = $this->sharedTeamOwnedBy($owner, 'Globex');
        $person = $this->addToTeam($team, 'member');
        $this->attachToTeam($otherTeam, $person, 'member');
        $this->makeCurrentTeam($person, $otherTeam);

        $this->actingAs($owner)
            ->put(route('team-members.update', [$team, $person]), ['role' => 'admin'])
            ->assertSessionHasNoErrors();

        $this->assertSame('admin', $this->roleOn($team, $person));
        $this->assertSame('member', $this->roleOn($otherTeam, $person));
        $this->assertSame($otherTeam->id, $this->currentTeamIdOf($person));
    }

    public function test_owners_of_another_team_cannot_change_roles()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $member = $this->addToTeam($team, 'member');
        $stranger = $this->userWithPersonalTeam();

        $this->actingAs($stranger)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('member', $this->roleOn($team, $member));
    }

    public function test_unknown_roles_are_rejected()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($owner)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'superuser'])
            ->assertSessionHasErrors('role');

        $this->assertSame('member', $this->roleOn($team, $member));
    }

    public function test_permission_to_change_roles_follows_configuration()
    {
        config(['teams.roles.admin.permissions' => ['team:update', 'members:remove']]);

        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $admin = $this->addToTeam($team, 'admin');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($admin)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('member', $this->roleOn($team, $member));
    }
}
