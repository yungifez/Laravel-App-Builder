<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: profile changes and team names.
 *
 * Updating the profile must not rename teams the person did not ask to
 * rename: a personal team they gave their own name keeps it when only the
 * email changes, shared teams they own are never renamed, and other people's
 * teams are never touched.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_changing_only_the_email_keeps_a_custom_personal_team_name()
    {
        $user = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);
        $personal = $this->personalTeamOf($user);
        $personal->forceFill(['name' => 'Analytical Engines'])->save();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Ada Lovelace',
                'email' => 'new-address@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Analytical Engines', $this->teamName($personal));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_changing_the_name_never_renames_shared_teams_the_person_owns()
    {
        $user = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);
        $shared = $this->sharedTeamOwnedBy($user, 'Difference Engine Club');

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Grace Hopper',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Difference Engine Club', $this->teamName($shared));
        $this->assertSame('Grace Hopper', $user->fresh()->name);
    }

    public function test_changing_the_name_never_renames_other_peoples_teams()
    {
        $other = $this->userWithPersonalTeam(['name' => 'Olivia Owner']);
        $othersPersonal = $this->personalTeamOf($other);
        $user = $this->addToTeam($othersPersonal, 'admin', ['name' => 'Ada Lovelace']);

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Grace Hopper',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame("Olivia Owner's Team", $this->teamName($othersPersonal));
    }

    public function test_people_without_any_team_can_update_their_profile()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Grace Hopper',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('Grace Hopper', $user->fresh()->name);
    }
}
