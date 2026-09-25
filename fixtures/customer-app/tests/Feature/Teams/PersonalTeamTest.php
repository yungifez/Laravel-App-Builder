<?php

namespace Tests\Feature\Teams;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PersonalTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_personal_team_owned_by_the_new_user()
    {
        $this->skipUnlessFortifyHas(Features::registration());

        $this->post(route('register.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $team = $user->currentTeam;

        $this->assertNotNull($team);
        $this->assertTrue($team->personal_team);
        $this->assertSame("Ada's Team", $team->name);
        $this->assertSame(TeamRole::Owner, $user->teamRole($team));
    }
}
