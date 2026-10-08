<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: plain members can see their team but cannot manage it.
 *
 * Members cannot rename the team, change roles or remove people, and the
 * settings page tells them so.
 */
class MemberRestrictionsTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_members_cannot_rename_the_team()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($member)
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_members_cannot_change_roles()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $member = $this->addToTeam($team, 'member');
        $admin = $this->addToTeam($team, 'admin');

        $this->actingAs($member)
            ->put(route('team-members.update', [$team, $admin]), ['role' => 'member'])
            ->assertForbidden();

        $this->actingAs($member)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('admin', $this->roleOn($team, $admin));
        $this->assertSame('member', $this->roleOn($team, $member));
    }

    public function test_members_cannot_remove_anyone()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $member = $this->addToTeam($team, 'member');
        $admin = $this->addToTeam($team, 'admin');

        $this->actingAs($member)
            ->delete(route('team-members.destroy', [$team, $admin]))
            ->assertForbidden();

        $this->assertTrue($this->isInTeam($team, $admin));
    }

    public function test_the_settings_page_shows_members_they_cannot_manage_the_team()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $member = $this->addToTeam($team, 'member');
        $this->makeCurrentTeam($member, $team);

        $this->actingAs($member)
            ->get(route('teams.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Team')
                ->where('team.id', $team->id)
                ->where('can.updateTeam', false)
                ->where('can.updateMemberRoles', false)
                ->where('can.removeMembers', false)
            );
    }
}
