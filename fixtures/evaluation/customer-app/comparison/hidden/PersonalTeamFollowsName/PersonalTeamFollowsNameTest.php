<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\PersonalTeamFollowsName;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task personal-team-follows-name: changing your name in the profile renames
 * your personal team the way sign-up names it ("<first name>'s Team").
 *
 * Deliberately not checked: what happens to a personal team the person had
 * already renamed themselves when they then change their name. Profile
 * updates that keep the name, shared teams and other people's teams are
 * guards (Guards/ProfileTest.php).
 */
class PersonalTeamFollowsNameTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    private function changeName(User $user, string $name, ?string $email = null): void
    {
        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $name,
                'email' => $email ?? $user->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));
    }

    public function test_changing_the_name_renames_the_personal_team()
    {
        $user = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);
        $personal = $this->personalTeamOf($user);

        $this->changeName($user, 'Grace Hopper');

        $this->assertSame("Grace's Team", $this->teamName($personal));
    }

    public function test_a_person_who_signed_up_gets_their_personal_team_renamed()
    {
        $this->post(route('register.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();
        Auth::logout();

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $personal = $this->personalTeamOf($user);

        $this->changeName($user, 'Grace Brewster Hopper');

        $this->assertSame("Grace's Team", $this->teamName($personal));
    }

    public function test_the_personal_team_is_renamed_even_when_working_in_another_team()
    {
        $user = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);
        $personal = $this->personalTeamOf($user);
        $shared = $this->sharedTeamOwnedBy($user, 'Acme');
        $this->assertSame($shared->id, $this->currentTeamIdOf($user));

        $this->changeName($user, 'Grace Hopper');

        $this->assertSame("Grace's Team", $this->teamName($personal));
        $this->assertSame('Acme', $this->teamName($shared));
        $this->assertSame($shared->id, $this->currentTeamIdOf($user));
    }

    public function test_changing_name_and_email_together_renames_the_personal_team()
    {
        $user = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);
        $personal = $this->personalTeamOf($user);

        $this->changeName($user, 'Grace Hopper', 'grace@example.com');

        $this->assertSame("Grace's Team", $this->teamName($personal));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_only_the_persons_own_personal_team_is_renamed()
    {
        $other = $this->userWithPersonalTeam(['name' => 'Olivia Owner']);
        $othersPersonal = $this->personalTeamOf($other);
        $user = $this->addToTeam($othersPersonal, 'admin', ['name' => 'Ada Lovelace']);
        $personal = $this->personalTeamOf($user);

        $this->changeName($user, 'Grace Hopper');

        $this->assertSame("Grace's Team", $this->teamName($personal));
        $this->assertSame("Olivia Owner's Team", $this->teamName($othersPersonal));
    }
}
