<?php

namespace Tests\Hidden\LeaveTeam;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task "leave-team": people can leave a team they were added to.
 *
 * Contract: DELETE route('team-membership.destroy', $team) by the signed-in user.
 */
class LeaveTeamTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_a_member_can_leave_a_team()
    {
        [$team] = $this->teamWithOwner();
        $member = $this->member($team);

        $this->actingAs($member)->delete(route('team-membership.destroy', $team));

        $this->assertFalse($this->isIn($member, $team));
    }

    public function test_an_admin_can_leave_a_team()
    {
        [$team] = $this->teamWithOwner();
        $admin = $this->member($team, 'admin');

        $this->actingAs($admin)->delete(route('team-membership.destroy', $team));

        $this->assertFalse($this->isIn($admin, $team));
    }

    public function test_someone_who_leaves_the_team_they_work_in_moves_to_their_personal_team()
    {
        [$team] = $this->teamWithOwner();
        $member = $this->member($team);
        $this->workIn($member, $team);

        $this->actingAs($member)->delete(route('team-membership.destroy', $team));

        $this->assertSame($this->personalTeamOf($member)->id, $member->fresh()->current_team_id);
    }

    public function test_the_owner_cannot_leave_so_the_team_keeps_its_owner()
    {
        [$team, $owner] = $this->teamWithOwner();

        $this->actingAs($owner)->delete(route('team-membership.destroy', $team));

        $this->assertTrue($this->isIn($owner, $team));
        $this->assertSame(1, $team->members()->wherePivot('role', 'owner')->count());
    }

    public function test_leaving_does_not_remove_anyone_else()
    {
        [$team, $owner] = $this->teamWithOwner();
        $member = $this->member($team);
        $other = $this->member($team);

        $this->actingAs($member)->delete(route('team-membership.destroy', $team));

        $this->assertTrue($this->isIn($other, $team));
        $this->assertTrue($this->isIn($owner, $team));
    }

    public function test_someone_outside_the_team_cannot_change_it()
    {
        [$team, $owner] = $this->teamWithOwner();
        $member = $this->member($team);
        $outsider = $this->member(Team::factory()->create());

        $this->actingAs($outsider)->delete(route('team-membership.destroy', $team));

        $this->assertSame(2, $team->members()->count());
        $this->assertTrue($this->isIn($member, $team));
    }
}
