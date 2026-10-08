<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: a team has exactly one owner, whose role cannot be changed and who
 * cannot be removed, through the existing role and removal actions.
 *
 * Beyond the app's own suite: the owner acting on themselves, and admins
 * trying to hand out the owner role.
 */
class OwnerProtectionTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_the_owner_cannot_change_their_own_role()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);

        $this->actingAs($owner)
            ->put(route('team-members.update', [$team, $owner]), ['role' => 'admin'])
            ->assertSessionHasErrors('role');

        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertSame(1, $this->ownerCount($team));
    }

    public function test_admins_cannot_change_the_owners_role()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');

        $this->actingAs($admin)
            ->put(route('team-members.update', [$team, $owner]), ['role' => 'member'])
            ->assertSessionHasErrors('role');

        $this->assertSame('owner', $this->roleOn($team, $owner));
    }

    public function test_admins_cannot_hand_out_the_owner_role()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($admin)
            ->put(route('team-members.update', [$team, $member]), ['role' => 'owner'])
            ->assertSessionHasErrors('role');

        $this->assertSame('member', $this->roleOn($team, $member));
        $this->assertSame(1, $this->ownerCount($team));
    }

    public function test_the_owner_cannot_remove_themselves()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', [$team, $owner]))
            ->assertSessionHasErrors('member');

        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertSame($team->id, $this->currentTeamIdOf($owner));
    }

    public function test_admins_cannot_remove_the_owner()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');

        $this->actingAs($admin)
            ->delete(route('team-members.destroy', [$team, $owner]))
            ->assertSessionHasErrors('member');

        $this->assertSame('owner', $this->roleOn($team, $owner));
    }
}
