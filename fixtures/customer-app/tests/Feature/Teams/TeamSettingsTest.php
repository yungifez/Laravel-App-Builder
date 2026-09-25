<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithTeams;
use Tests\TestCase;

class TeamSettingsTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('teams.edit'))->assertRedirect(route('login'));
    }

    public function test_members_see_their_current_team_and_its_members()
    {
        $owner = User::factory()->create(['name' => 'Olivia Owner']);
        $team = $this->createTeamOwnedBy($owner);
        $member = $this->addTeamMember($team, TeamRole::Member, ['name' => 'Max Member']);
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($member)
            ->get(route('teams.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Team')
                ->where('team.name', 'Acme')
                ->has('members', 2)
                ->where('members.0.name', 'Max Member')
                ->where('members.0.role', 'member')
                ->where('members.1.name', 'Olivia Owner')
                ->where('members.1.role', 'owner')
                ->where('can.updateTeam', false)
                ->where('can.updateMemberRoles', false)
                ->where('can.removeMembers', false)
            );
    }

    public function test_owners_can_manage_the_team()
    {
        $owner = User::factory()->create();
        $this->createTeamOwnedBy($owner);

        $this->actingAs($owner)
            ->get(route('teams.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.updateTeam', true)
                ->where('can.updateMemberRoles', true)
                ->where('can.removeMembers', true)
                ->where('assignableRoles.0.value', 'admin')
                ->where('assignableRoles.1.value', 'member')
            );
    }

    public function test_users_without_a_team_get_not_found()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('teams.edit'))
            ->assertNotFound();
    }
}
