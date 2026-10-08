<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\UniqueTeamNames;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Task unique-team-names: a team cannot be renamed to the name of another
 * team the person renaming it already belongs to.
 *
 * Deliberately not checked: letter case or spacing variants, and which
 * message is shown. Saving a team under its own unchanged name is a guard
 * (Guards/TeamRenameTest.php).
 */
class UniqueTeamNamesTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_a_team_cannot_take_the_name_of_another_team_the_owner_is_in()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $this->sharedTeamOwnedBy($owner, 'Globex');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Globex'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_the_name_of_a_team_the_admin_belongs_to_as_a_member_is_also_taken()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $admin = $this->addToTeam($team, 'admin');
        $elsewhere = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Globex');
        $this->attachToTeam($elsewhere, $admin, 'member');

        $this->actingAs($admin)
            ->patch(route('teams.update', $team), ['name' => 'Globex'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_the_personal_team_name_is_also_taken()
    {
        $owner = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => "Ada Lovelace's Team"])
            ->assertSessionHasErrors('name');

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_a_name_used_only_by_teams_the_person_is_not_in_is_allowed()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Globex');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Globex'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertSame('Globex', $this->teamName($team));
    }

    public function test_a_new_unused_name_is_still_accepted()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $this->sharedTeamOwnedBy($owner, 'Globex');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Initech'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertSame('Initech', $this->teamName($team));
    }
}
