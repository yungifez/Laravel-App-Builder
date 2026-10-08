<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\OwnerAccountDeletion;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task owner-account-deletion: someone who owns a team that other people are
 * in cannot delete their account; everyone else still can.
 *
 * Contract: the existing DELETE route('profile.destroy') with `password`
 * refuses with a validation error (session errors under any key) and keeps
 * the person signed in.
 *
 * Deliberately not checked: the error key and message, the order in which a
 * wrong password and an owned team are reported, and anything about what
 * happens to teams (no ownership handover or team deletion is asked for).
 */
class OwnerAccountDeletionTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    private function deleteAccount(User $user)
    {
        return $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password']);
    }

    private function assertRefused(User $user, $response): void
    {
        $response->assertSessionHasErrors();
        $this->assertNotNull($user->fresh());
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_owner_of_a_team_with_other_members_cannot_delete_their_account()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');

        $this->assertRefused($owner, $this->deleteAccount($owner));

        $this->assertSame('owner', $this->roleOn($team, $owner));
        $this->assertSame('member', $this->roleOn($team, $member));
    }

    public function test_a_personal_team_that_someone_else_was_added_to_also_counts()
    {
        $owner = $this->userWithPersonalTeam();
        $personal = $this->personalTeamOf($owner);
        $this->addToTeam($personal, 'admin');

        $this->assertRefused($owner, $this->deleteAccount($owner));

        $this->assertSame('owner', $this->roleOn($personal, $owner));
    }

    public function test_the_owner_of_a_shared_team_nobody_else_is_in_can_delete_their_account()
    {
        $owner = $this->userWithPersonalTeam();
        $this->sharedTeamOwnedBy($owner);

        $this->deleteAccount($owner)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($owner->fresh());
    }

    public function test_once_everyone_else_is_removed_the_owner_can_delete_their_account()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $member = $this->addToTeam($team, 'member');

        $this->assertRefused($owner, $this->deleteAccount($owner));

        $this->actingAs($owner)
            ->delete(route('team-members.destroy', [$team, $member]))
            ->assertSessionHasNoErrors();

        $this->deleteAccount($owner)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertNull($owner->fresh());
    }

    public function test_an_admin_of_someone_elses_team_can_still_delete_their_account()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');
        $this->addToTeam($team, 'member');

        $this->deleteAccount($admin)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertNull($admin->fresh());
        $this->assertSame('owner', $this->roleOn($team, $owner));
    }
}
