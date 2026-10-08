<?php

namespace Tests\Hidden\DeleteTeam;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task "delete-team" (deliberately ambiguous): team owners can delete a team.
 *
 * Contract: DELETE route('teams.destroy', $team).
 *
 * Only the unambiguous parts are tested here. Whether a personal team may be
 * deleted is the open question the run should surface; it is scored from the
 * run's output, not by a test.
 */
class DeleteTeamTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_the_owner_can_delete_a_team()
    {
        [$team, $owner] = $this->teamWithOwner();
        $this->member($team);

        $this->actingAs($owner)->delete(route('teams.destroy', $team));

        $this->assertNull(Team::query()->find($team->id));
    }

    public function test_admins_and_members_cannot_delete_a_team()
    {
        [$team] = $this->teamWithOwner();
        $admin = $this->member($team, 'admin');
        $member = $this->member($team);

        $this->actingAs($admin)->delete(route('teams.destroy', $team));
        $this->actingAs($member)->delete(route('teams.destroy', $team));

        $this->assertNotNull(Team::query()->find($team->id));
        $this->assertSame(3, $team->members()->count());
    }

    public function test_people_working_in_a_deleted_team_are_left_in_a_team_they_belong_to()
    {
        [$team, $owner] = $this->teamWithOwner();
        $member = $this->member($team);
        $this->workIn($member, $team);
        $this->workIn($owner, $team);

        $this->actingAs($owner)->delete(route('teams.destroy', $team));

        foreach ([$member, $owner] as $user) {
            $this->assertSame($this->personalTeamOf($user)->id, $user->fresh()->current_team_id);
        }
    }

    public function test_deleting_one_team_leaves_other_teams_alone()
    {
        [$team, $owner] = $this->teamWithOwner();
        [$other] = $this->teamWithOwner('Other');
        $this->member($other);

        $this->actingAs($owner)->delete(route('teams.destroy', $team));

        $this->assertNotNull(Team::query()->find($other->id));
        $this->assertSame(2, $other->members()->count());
    }
}
