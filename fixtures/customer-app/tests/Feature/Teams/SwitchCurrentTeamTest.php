<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTeams;
use Tests\TestCase;

class SwitchCurrentTeamTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_members_can_switch_to_a_team_they_belong_to()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());
        $member = $this->addTeamMember($team, TeamRole::Member);

        $this->actingAs($member)
            ->put(route('current-team.update', $team))
            ->assertRedirect(route('teams.edit'));

        $this->assertSame($team->id, $member->fresh()->current_team_id);
    }

    public function test_users_cannot_switch_to_a_team_they_do_not_belong_to()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());
        $outsider = User::factory()->withPersonalTeam()->create();
        $originalTeamId = $outsider->current_team_id;

        $this->actingAs($outsider)
            ->put(route('current-team.update', $team))
            ->assertForbidden();

        $this->assertSame($originalTeamId, $outsider->fresh()->current_team_id);
    }
}
