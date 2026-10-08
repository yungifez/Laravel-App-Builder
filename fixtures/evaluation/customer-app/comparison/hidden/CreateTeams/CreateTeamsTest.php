<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\CreateTeams;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task create-teams: signed-in people can start new teams.
 *
 * Contract: POST route('teams.store') (URL settings/teams) with a required
 * `name`.
 *
 * Deliberately not checked (open question): whether the creator is switched
 * into the new team, and where the response redirects. Also not checked: a
 * maximum name length or any limit on how many teams someone may start.
 */
class CreateTeamsTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_a_signed_in_person_can_start_a_team_and_owns_it()
    {
        $user = $this->userWithPersonalTeam();

        $this->actingAs($user)
            ->post(route('teams.store'), ['name' => 'Globex'])
            ->assertSessionHasNoErrors();

        $team = Team::where('name', 'Globex')->firstOrFail();

        $this->assertFalse((bool) $team->personal_team);
        $this->assertSame('owner', $this->roleOn($team, $user));
        $this->assertSame(1, DB::table('team_user')->where('team_id', $team->id)->count());
    }

    public function test_starting_a_team_keeps_the_persons_other_teams()
    {
        $user = $this->userWithPersonalTeam();
        $personal = $this->personalTeamOf($user);
        $shared = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $this->attachToTeam($shared, $user, 'admin');

        $this->actingAs($user)
            ->post(route('teams.store'), ['name' => 'Globex'])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $personal->fresh()->personal_team);
        $this->assertSame('owner', $this->roleOn($personal, $user));
        $this->assertSame('admin', $this->roleOn($shared, $user));
        $this->assertSame(3, DB::table('team_user')->where('user_id', $user->id)->count());
    }

    public function test_the_new_team_appears_in_the_persons_team_list()
    {
        $user = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);

        $this->actingAs($user)
            ->post(route('teams.store'), ['name' => 'Globex'])
            ->assertSessionHasNoErrors();

        $this->actingAs($user->fresh())
            ->get(route('teams.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('teams', 2)
                ->where('teams.0.name', "Ada Lovelace's Team")
                ->where('teams.1.name', 'Globex')
            );
    }

    public function test_a_name_is_required()
    {
        $user = $this->userWithPersonalTeam();

        $this->actingAs($user)
            ->post(route('teams.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Team::count());
    }

    public function test_guests_cannot_start_a_team()
    {
        $this->post(route('teams.store'), ['name' => 'Globex'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Team::count());
    }

    public function test_other_teams_are_not_affected()
    {
        $other = $this->userWithPersonalTeam();
        $acme = $this->sharedTeamOwnedBy($other, 'Acme');
        $user = $this->userWithPersonalTeam();

        $this->actingAs($user)
            ->post(route('teams.store'), ['name' => 'Globex'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->isInTeam($acme, $user));
        $this->assertSame(1, DB::table('team_user')->where('team_id', $acme->id)->count());
        $this->assertSame($acme->id, $this->currentTeamIdOf($other));
    }
}
