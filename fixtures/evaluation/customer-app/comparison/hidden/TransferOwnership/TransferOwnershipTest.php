<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\TransferOwnership;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task transfer-ownership (authorized change): the owner can hand ownership
 * to another member of the team and becomes an admin.
 *
 * Contract: PUT route('team-owner.update', $team) (URL
 * settings/teams/{team}/owner) with `user_id` = the member who becomes owner.
 *
 * Deliberately not checked: the redirect target, the success message,
 * handing over a personal team, and handing ownership to yourself.
 */
class TransferOwnershipTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_the_owner_can_hand_ownership_to_an_admin()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');

        $this->actingAs($owner)
            ->put(route('team-owner.update', $team), ['user_id' => $admin->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('owner', $this->roleOn($team, $admin));
        $this->assertSame('admin', $this->roleOn($team, $owner));
        $this->assertSame(1, $this->ownerCount($team));
    }

    public function test_the_owner_can_hand_ownership_to_a_plain_member()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');
        $bystander = $this->addToTeam($team, 'member');

        $this->actingAs($owner)
            ->put(route('team-owner.update', $team), ['user_id' => $member->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('owner', $this->roleOn($team, $member));
        $this->assertSame('admin', $this->roleOn($team, $owner));
        $this->assertSame('member', $this->roleOn($team, $bystander));
        $this->assertSame(1, $this->ownerCount($team));
    }

    public function test_admins_and_members_cannot_hand_over_ownership()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($admin)
            ->put(route('team-owner.update', $team), ['user_id' => $admin->id])
            ->assertForbidden();

        $this->actingAs($member)
            ->put(route('team-owner.update', $team), ['user_id' => $admin->id])
            ->assertForbidden();

        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertSame('admin', $this->roleOn($team, $admin));
        $this->assertSame('member', $this->roleOn($team, $member));
    }

    public function test_owners_of_another_team_cannot_hand_over_ownership()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');
        $stranger = $this->userWithPersonalTeam();

        $this->actingAs($stranger)
            ->put(route('team-owner.update', $team), ['user_id' => $member->id])
            ->assertForbidden();

        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertSame('member', $this->roleOn($team, $member));
    }

    public function test_ownership_cannot_go_to_someone_outside_the_team()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $outsider = $this->userWithPersonalTeam();

        $this->actingAs($owner)
            ->put(route('team-owner.update', $team), ['user_id' => $outsider->id])
            ->assertSessionHasErrors('user_id');

        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertFalse($this->isInTeam($team, $outsider));
        $this->assertSame(1, $this->ownerCount($team));
    }

    public function test_the_new_owner_has_the_owners_protections_and_rights()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($owner)
            ->put(route('team-owner.update', $team), ['user_id' => $member->id])
            ->assertSessionHasNoErrors();

        // The previous owner, now an admin, cannot remove the new owner.
        $this->actingAs($owner)
            ->delete(route('team-members.destroy', [$team, $member]))
            ->assertSessionHasErrors('member');
        $this->assertSame('owner', $this->roleOn($team, $member));

        // The new owner can manage the team, including the previous owner.
        $this->actingAs($member)
            ->patch(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $this->teamName($team));

        $this->actingAs($member)
            ->put(route('team-members.update', [$team, $owner]), ['role' => 'member'])
            ->assertSessionHasNoErrors();
        $this->assertSame('member', $this->roleOn($team, $owner));
    }

    public function test_the_new_owner_can_hand_ownership_on_and_the_previous_owner_cannot_take_it_back()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');

        $this->actingAs($owner)
            ->put(route('team-owner.update', $team), ['user_id' => $member->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->put(route('team-owner.update', $team), ['user_id' => $owner->id])
            ->assertForbidden();
        $this->assertSame('owner', $this->roleOn($team, $member));

        $this->actingAs($member)
            ->put(route('team-owner.update', $team), ['user_id' => $owner->id])
            ->assertSessionHasNoErrors();

        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertSame('admin', $this->roleOn($team, $member));
        $this->assertSame(1, $this->ownerCount($team));
    }
}
