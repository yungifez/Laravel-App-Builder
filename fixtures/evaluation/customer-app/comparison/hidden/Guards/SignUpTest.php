<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: signing up gives the person their own personal team.
 *
 * Covers cases the app's suite does not: two people with the same first name,
 * and sign-up not touching anyone else's team.
 */
class SignUpTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    private function register(string $name, string $email): void
    {
        $this->post(route('register.store'), [
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();
    }

    public function test_sign_up_creates_a_personal_team_named_after_the_first_name()
    {
        $this->register('Ada Lovelace', 'ada@example.com');

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $team = $this->personalTeamOf($user);

        $this->assertSame("Ada's Team", $team->name);
        $this->assertSame($team->id, $this->currentTeamIdOf($user));
        $this->assertSame('owner', $this->roleOn($team, $user));
        $this->assertSame(1, DB::table('team_user')->where('team_id', $team->id)->count());
    }

    public function test_two_people_with_the_same_first_name_can_both_sign_up()
    {
        $this->register('Ada Lovelace', 'ada@example.com');
        Auth::logout();
        $this->register('Ada Byron', 'byron@example.com');

        $first = User::where('email', 'ada@example.com')->firstOrFail();
        $second = User::where('email', 'byron@example.com')->firstOrFail();

        $firstTeam = $this->personalTeamOf($first);
        $secondTeam = $this->personalTeamOf($second);

        $this->assertNotSame($firstTeam->id, $secondTeam->id);
        $this->assertSame("Ada's Team", $firstTeam->name);
        $this->assertSame("Ada's Team", $secondTeam->name);
        $this->assertSame($secondTeam->id, $this->currentTeamIdOf($second));
    }

    public function test_sign_up_does_not_add_the_person_to_other_teams()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);

        $this->register('Grace Hopper', 'grace@example.com');

        $user = User::where('email', 'grace@example.com')->firstOrFail();

        $this->assertFalse($this->isInTeam($team, $user));
        $this->assertSame(1, DB::table('team_user')->where('user_id', $user->id)->count());
    }
}
