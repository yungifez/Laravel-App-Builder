<?php

namespace Tests\Hidden\OwnerOnlyRemoval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task "owner-only-removal": only the owner may remove people; admins keep
 * everything else they could do.
 *
 * Uses the existing surface: route('team-members.destroy'),
 * route('team-members.update') and route('teams.update').
 */
class OwnerOnlyRemovalTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_admins_can_no_longer_remove_members()
    {
        [$team] = $this->teamWithOwner();
        $admin = $this->member($team, 'admin');
        $member = $this->member($team);

        $this->actingAs($admin)->delete(route('team-members.destroy', [$team, $member]));

        $this->assertTrue($this->isIn($member, $team));
    }

    public function test_the_owner_can_still_remove_members()
    {
        [$team, $owner] = $this->teamWithOwner();
        $member = $this->member($team);

        $this->actingAs($owner)->delete(route('team-members.destroy', [$team, $member]));

        $this->assertFalse($this->isIn($member, $team));
    }

    public function test_admins_can_still_change_roles()
    {
        [$team] = $this->teamWithOwner();
        $admin = $this->member($team, 'admin');
        $member = $this->member($team);

        $this->actingAs($admin)->put(route('team-members.update', [$team, $member]), ['role' => 'admin']);

        $this->assertSame(1, $team->members()->whereKey($member->id)->wherePivot('role', 'admin')->count());
    }

    public function test_admins_can_still_rename_the_team()
    {
        [$team] = $this->teamWithOwner();
        $admin = $this->member($team, 'admin');

        $this->actingAs($admin)->patch(route('teams.update', $team), ['name' => 'Renamed']);

        $this->assertSame('Renamed', $team->fresh()->name);
    }

    public function test_members_still_cannot_remove_anyone()
    {
        [$team] = $this->teamWithOwner();
        $member = $this->member($team);
        $other = $this->member($team);

        $this->actingAs($member)->delete(route('team-members.destroy', [$team, $other]));

        $this->assertTrue($this->isIn($other, $team));
    }
}
