<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTeams;
use Tests\TestCase;

class UpdateTeamTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_owners_can_rename_the_team()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertSame('Renamed', $team->fresh()->name);
    }

    public function test_admins_can_rename_the_team()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());
        $admin = $this->addTeamMember($team, TeamRole::Admin);

        $this->actingAs($admin)
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertRedirect(route('teams.edit'));

        $this->assertSame('Renamed', $team->fresh()->name);
    }

    public function test_members_cannot_rename_the_team()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());
        $member = $this->addTeamMember($team, TeamRole::Member);

        $this->actingAs($member)
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertSame('Acme', $team->fresh()->name);
    }

    public function test_outsiders_cannot_rename_the_team()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());

        $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_permission_to_rename_follows_configuration()
    {
        config(['teams.roles.admin.permissions' => []]);

        $team = $this->createTeamOwnedBy(User::factory()->create());
        $admin = $this->addTeamMember($team, TeamRole::Admin);

        $this->actingAs($admin)
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_team_name_is_required()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => ''])
            ->assertSessionHasErrors('name');
    }
}
