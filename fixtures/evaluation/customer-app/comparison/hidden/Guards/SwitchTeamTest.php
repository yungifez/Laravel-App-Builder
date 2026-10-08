<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: switching the current team and the team settings page.
 *
 * Beyond the app's own suite: the settings page follows the switch, the
 * switcher lists exactly the person's own teams, switching changes no roles,
 * and admins are told they can manage the team.
 */
class SwitchTeamTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_after_switching_the_settings_page_shows_the_chosen_team()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($member)
            ->put(route('current-team.update', $team))
            ->assertRedirect(route('teams.edit'));

        $this->assertSame($team->id, $this->currentTeamIdOf($member));

        $this->actingAs($member->fresh())
            ->get(route('teams.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Team')
                ->where('team.id', $team->id)
                ->where('team.name', 'Acme')
            );
    }

    public function test_switching_changes_no_roles()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $admin = $this->addToTeam($team, 'admin');
        $personal = $this->personalTeamOf($admin);

        $this->actingAs($admin)->put(route('current-team.update', $team));
        $this->actingAs($admin)->put(route('current-team.update', $personal));

        $this->assertSame($personal->id, $this->currentTeamIdOf($admin));
        $this->assertSame('admin', $this->roleOn($team, $admin));
        $this->assertSame('owner', $this->roleOn($personal, $admin));
        $this->assertSame('owner', $this->roleOn($team, $owner));
    }

    public function test_the_switcher_lists_exactly_the_persons_teams()
    {
        $owner = $this->userWithPersonalTeam(['name' => 'Olivia Owner']);
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $member = $this->addToTeam($team, 'member', ['name' => 'Max Member']);
        $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Zeta Hidden');

        $this->actingAs($member)
            ->get(route('teams.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('teams', 2)
                ->where('teams.0.name', 'Acme')
                ->where('teams.1.name', "Max Member's Team")
            );
    }

    public function test_admins_are_told_they_can_manage_the_team()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $admin = $this->addToTeam($team, 'admin');
        $this->makeCurrentTeam($admin, $team);

        $this->actingAs($admin)
            ->get(route('teams.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('team.id', $team->id)
                ->where('can.updateTeam', true)
                ->where('can.updateMemberRoles', true)
                ->where('can.removeMembers', true)
            );
    }

    public function test_people_cannot_switch_to_a_team_they_are_not_in()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam());
        $outsider = $this->userWithPersonalTeam();
        $before = $this->currentTeamIdOf($outsider);

        $this->actingAs($outsider)
            ->put(route('current-team.update', $team))
            ->assertForbidden();

        $this->assertSame($before, $this->currentTeamIdOf($outsider));
    }
}
