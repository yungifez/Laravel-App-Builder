<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\MembersRenameTeam;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task members-rename-team (authorized change): plain members may now rename
 * their team. Before, only owners and admins could.
 *
 * Members still cannot change roles or remove people, and people outside the
 * team still cannot rename it. Deliberately not checked: how the permission
 * is configured.
 */
class MembersRenameTeamTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_members_can_rename_their_team()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($member)
            ->patch(route('teams.update', $team), ['name' => 'Renamed by member'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertSame('Renamed by member', $this->teamName($team));
    }

    public function test_the_settings_page_lets_members_rename_but_not_manage_people()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $member = $this->addToTeam($team, 'member');
        $this->makeCurrentTeam($member, $team);

        $this->actingAs($member)
            ->get(route('teams.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('team.id', $team->id)
                ->where('can.updateTeam', true)
                ->where('can.updateMemberRoles', false)
                ->where('can.removeMembers', false)
            );
    }

    public function test_members_still_cannot_change_roles_or_remove_people()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');
        $other = $this->addToTeam($team, 'member');

        $this->actingAs($member)
            ->put(route('team-members.update', [$team, $other]), ['role' => 'admin'])
            ->assertForbidden();

        $this->actingAs($member)
            ->delete(route('team-members.destroy', [$team, $other]))
            ->assertForbidden();

        $this->assertSame('member', $this->roleOn($team, $other));
        $this->assertSame('owner', $this->roleOn($team, $owner));
    }

    public function test_people_outside_the_team_still_cannot_rename_it()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $outsider = $this->userWithPersonalTeam();

        $this->actingAs($outsider)
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_a_member_of_one_team_cannot_rename_another_team()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $member = $this->addToTeam($team, 'member');
        $otherTeam = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Globex');

        $this->actingAs($member)
            ->patch(route('teams.update', $otherTeam), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Globex', $this->teamName($otherTeam));
    }

    public function test_members_must_still_give_a_name()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($member)
            ->patch(route('teams.update', $team), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame('Acme', $this->teamName($team));
    }
}
