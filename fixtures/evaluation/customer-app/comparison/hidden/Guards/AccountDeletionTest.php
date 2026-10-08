<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: deleting an account.
 *
 * People who own only their personal team, and people who are admins or
 * members of someone else's team, can delete their account with their
 * password. The teams they leave behind keep their owner and other members.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_someone_who_owns_only_their_personal_team_can_delete_their_account()
    {
        $user = $this->userWithPersonalTeam();

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_admins_and_members_of_someone_elses_team_can_delete_their_account()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');
        $member = $this->addToTeam($team, 'member');
        $this->makeCurrentTeam($admin, $team);

        $this->actingAs($admin)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertNull($admin->fresh());
        $this->assertNotNull($team->fresh());
        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertSame('member', $this->roleOn($team, $member));
        $this->assertSame(1, $this->ownerCount($team));
    }

    public function test_the_current_password_is_required()
    {
        $user = $this->userWithPersonalTeam();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }
}
