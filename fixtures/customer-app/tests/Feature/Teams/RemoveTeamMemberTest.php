<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTeams;
use Tests\TestCase;

class RemoveTeamMemberTest extends TestCase
{
    use InteractsWithTeams, RefreshDatabase;

    public function test_owners_can_remove_members()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);
        $member = $this->addTeamMember($team, TeamRole::Member);

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', [$team, $member]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertFalse($member->belongsToTeam($team));
    }

    public function test_a_removed_member_working_in_the_team_moves_to_their_personal_team()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);
        $member = $this->addTeamMember($team, TeamRole::Member);
        $personalTeam = $member->currentTeam;
        $member->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($owner)->delete(route('team-members.destroy', [$team, $member]));

        $this->assertSame($personalTeam->id, $member->fresh()->current_team_id);
    }

    public function test_the_owner_cannot_be_removed()
    {
        $owner = User::factory()->create();
        $team = $this->createTeamOwnedBy($owner);
        $admin = $this->addTeamMember($team, TeamRole::Admin);

        $this->actingAs($admin)
            ->delete(route('team-members.destroy', [$team, $owner]))
            ->assertSessionHasErrors('member');

        $this->assertTrue($owner->belongsToTeam($team));
    }

    public function test_members_cannot_remove_other_members()
    {
        $team = $this->createTeamOwnedBy(User::factory()->create());
        $member = $this->addTeamMember($team, TeamRole::Member);
        $other = $this->addTeamMember($team, TeamRole::Member);

        $this->actingAs($member)
            ->delete(route('team-members.destroy', [$team, $other]))
            ->assertForbidden();

        $this->assertTrue($other->belongsToTeam($team));
    }
}
