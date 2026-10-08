<?php

// eval-canary-1983c6400b99c598893b8227

namespace Tests\Hidden\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Hidden\Support\TeamScenario;
use Tests\TestCase;

/**
 * Guard: owners and admins rename a team.
 *
 * Beyond the app's own suite: saving the form without changing the name,
 * the length limit, and renaming affecting only the chosen team.
 */
class TeamRenameTest extends TestCase
{
    use RefreshDatabase, TeamScenario;

    public function test_owners_and_admins_can_rename_the_team()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner);
        $admin = $this->addToTeam($team, 'admin');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Renamed by owner'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));
        $this->assertSame('Renamed by owner', $this->teamName($team));

        $this->actingAs($admin)
            ->patch(route('teams.update', $team), ['name' => 'Renamed by admin'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));
        $this->assertSame('Renamed by admin', $this->teamName($team));
    }

    public function test_saving_the_team_without_changing_its_name_succeeds()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Acme'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_a_personal_team_can_be_saved_without_changing_its_name()
    {
        $owner = $this->userWithPersonalTeam(['name' => 'Ada Lovelace']);
        $personal = $this->personalTeamOf($owner);

        $this->actingAs($owner)
            ->patch(route('teams.update', $personal), ['name' => "Ada Lovelace's Team"])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('teams.edit'));
    }

    public function test_names_longer_than_255_characters_are_rejected()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors('name');

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_owners_of_another_team_cannot_rename_it()
    {
        $team = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');
        $stranger = $this->userWithPersonalTeam();
        $this->sharedTeamOwnedBy($stranger, 'Globex');

        $this->actingAs($stranger)
            ->patch(route('teams.update', $team), ['name' => 'Taken over'])
            ->assertForbidden();

        $this->assertSame('Acme', $this->teamName($team));
    }

    public function test_renaming_changes_only_the_chosen_team()
    {
        $owner = $this->userWithPersonalTeam();
        $team = $this->sharedTeamOwnedBy($owner, 'Acme');
        $otherOfMine = $this->sharedTeamOwnedBy($owner, 'Globex');
        $someoneElses = $this->sharedTeamOwnedBy($this->userWithPersonalTeam(), 'Acme');

        $this->actingAs($owner)
            ->patch(route('teams.update', $team), ['name' => 'Initech'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Initech', $this->teamName($team));
        $this->assertSame('Globex', $this->teamName($otherOfMine));
        $this->assertSame('Acme', $this->teamName($someoneElses));
    }
}
