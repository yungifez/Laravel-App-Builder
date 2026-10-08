<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: owners and admins remove people from a team.
 *
 * Beyond the app's own suite: admins removing, the permission following
 * config/teams.php, where a removed person lands, and people outside the team.
 */
class MemberRemovalTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_admins_can_remove_members_and_other_admins()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');
        $otherAdmin = $this->addToTeam($team, 'admin');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($admin)
            ->delete(route('team-members.destroy', [$team, $member]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->actingAs($admin)
            ->delete(route('team-members.destroy', [$team, $otherAdmin]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertFalse($this->isInTeam($team, $member));
        $this->assertFalse($this->isInTeam($team, $otherAdmin));
        $this->assertSame('owner', $this->roleOn($team, $owner));
    }

    public function test_permission_to_remove_follows_configuration()
    {
        config(['teams.roles.admin.permissions' => ['team:update', 'members:update-role']]);

        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $admin = $this->addToTeam($team, 'admin');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($admin)
            ->delete(route('team-members.destroy', [$team, $member]))
            ->assertForbidden();

        $this->assertTrue($this->isInTeam($team, $member));
    }

    public function test_a_removed_member_working_in_the_team_lands_in_their_personal_team_first()
    {
        // The person joined another shared team before their personal team existed,
        // so that team has the lower id. They must still land in their personal team.
        $person = User::factory()->create();
        $olderTeam = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Older');
        $this->attachToTeam($olderTeam, $person, 'member');
        $personal = Team::factory()->personal()->ownedBy($person)->create(['name' => 'Personal']);

        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $this->attachToTeam($team, $person, 'member');
        $this->makeCurrentTeam($person, $team);

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', [$team, $person]))
            ->assertSessionHasNoErrors();

        $this->assertSame($personal->id, $this->currentTeamIdOf($person));
        $this->assertTrue($this->isInTeam($olderTeam, $person));
    }

    public function test_a_removed_member_working_in_another_team_stays_there()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $elsewhere = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Globex');
        $person = $this->addToTeam($team, 'member');
        $this->attachToTeam($elsewhere, $person, 'member');
        $this->makeCurrentTeam($person, $elsewhere);

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', [$team, $person]))
            ->assertSessionHasNoErrors();

        $this->assertSame($elsewhere->id, $this->currentTeamIdOf($person));
        $this->assertFalse($this->isInTeam($team, $person));
    }

    public function test_a_removed_member_loses_access_to_the_team()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $person = $this->addToTeam($team, 'member');

        $this->actingAs($owner)->delete(route('team-members.destroy', [$team, $person]));

        $this->actingAs($person)
            ->put(route('current-team.update', $team))
            ->assertForbidden();
    }

    public function test_people_outside_the_team_cannot_be_targeted()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $outsider = $this->userWithPersonalTeam();
        $outsidersTeam = $this->personalTeamOf($outsider);

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', [$team, $outsider]))
            ->assertNotFound();

        $this->assertSame('owner', $this->roleOn($outsidersTeam, $outsider));
    }

    public function test_owners_of_another_team_cannot_remove_people()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $member = $this->addToTeam($team, 'member');
        $stranger = $this->userWithPersonalTeam();

        $this->actingAs($stranger)
            ->delete(route('team-members.destroy', [$team, $member]))
            ->assertForbidden();

        $this->assertTrue($this->isInTeam($team, $member));
    }
}
