<?php

namespace Tests\Feature\Context;

use App\Actions\Projects\CreateProject;
use App\Context\ProjectNotes;
use App\Models\Project;
use App\Models\TestObservation;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class NotConnectedTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const PLANS_NOTES = <<<'MARKDOWN'
    ---
    capability: plans
    paths: [app/Models/Plan.php]
    effects:
        - to: teams
          strength: strong
          reason: Each team has one plan.
          source: owner
        - to: invoices
          strength: possible
          reason: Plans are billed.
          source: agent
    ---

    # Plans

    MARKDOWN;

    protected const TEAMS_NOTES = <<<'MARKDOWN'
    ---
    capability: teams
    paths: [app/Models/Team.php, tests/Feature/TeamTest.php]
    ---
    # Teams

    MARKDOWN;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            '.builder/project.md' => "# Acme\n",
            '.builder/capabilities/plans.md' => self::PLANS_NOTES,
            '.builder/capabilities/teams.md' => self::TEAMS_NOTES,
            'app/Models/Plan.php' => "<?php\n",
            'tests/Feature/TeamTest.php' => "<?php\n",
        ]));
        app(ProjectRepository::class)->import($this->project);
    }

    private function ruleOut(string $keys, ?string $revision = null): TestResponse
    {
        return $this->actingAs($this->owner)->put(route('projects.understanding.update', $this->project), [
            'part' => 'not_connected:plans',
            'body' => $keys,
            'revision' => $revision ?? app(ProjectNotes::class)->version($this->project),
        ]);
    }

    private function plansNotes(): string
    {
        return app(ProjectNotes::class)->files($this->project)['capabilities/plans.md'];
    }

    /**
     * @param  list<string>  $connections
     * @param  list<array{to: string, name: string}>  $notConnected
     */
    private function assertPlansShows(array $connections, array $notConnected): void
    {
        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('areas.0.key', 'plans')
                ->where('areas.0.connections', fn ($shown) => collect($shown)->pluck('to')->all() === $connections)
                ->where('areas.0.not_connected', $notConnected));
    }

    public function test_the_owner_rules_out_a_wrong_connection_and_can_put_it_back()
    {
        // Invoices has no notes of its own yet; the owner can still rule it out.
        $this->ruleOut('invoices')->assertSessionHasNoErrors();

        $this->assertStringContainsString("capability: plans\nnot_connected: [invoices]\n", $this->plansNotes());
        $this->assertPlansShows(['teams'], [['to' => 'invoices', 'name' => 'Invoices']]);

        // A wrong click is one click to undo, and the notes read as before.
        $this->ruleOut('')->assertSessionHasNoErrors();

        $this->assertSame(self::PLANS_NOTES, $this->plansNotes());
        $this->assertPlansShows(['teams', 'invoices'], []);
    }

    public function test_a_connection_the_tests_showed_stays_ruled_out()
    {
        // A teams test runs the plans code, so the tests connect the two too.
        TestObservation::create(['project_id' => $this->project->id, 'tests' => [
            ['id' => 'Tests\\Feature\\TeamTest::test_a_team_has_a_plan', 'file' => 'tests/Feature/TeamTest.php', 'groups' => []],
        ], 'files' => ['app/Models/Plan.php' => [0]]]);
        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('areas.0.connections.0.reason', 'Each team has one plan. Seen when your app\'s tests ran.'));

        $this->ruleOut('teams')->assertSessionHasNoErrors();

        // Neither the owner's line nor the tests bring it back.
        $this->assertPlansShows(['invoices'], [['to' => 'teams', 'name' => 'Teams']]);
    }

    public function test_a_stale_edit_or_a_part_ruled_out_from_itself_changes_nothing()
    {
        $stale = app(ProjectNotes::class)->version($this->project);
        $this->ruleOut('invoices')->assertSessionHasNoErrors();

        $this->ruleOut('teams', $stale)
            ->assertSessionHasErrors(['body' => 'The notes changed while you were editing. Try again on the updated version.']);
        $this->ruleOut("invoices\nplans")->assertSessionHasErrors(['body' => 'A part is always connected to itself.']);
        $this->ruleOut('Not a part')->assertSessionHasErrors('body');

        $this->assertStringContainsString("not_connected: [invoices]\n", $this->plansNotes());
        $this->assertPlansShows(['teams'], [['to' => 'invoices', 'name' => 'Invoices']]);
    }
}
