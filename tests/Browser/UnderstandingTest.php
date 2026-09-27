<?php

use App\Actions\Projects\CreateProject;
use App\Models\TestObservation;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;

uses(PreparesRuns::class);

/*
| What the owner learns about their app on its overview, in a real browser:
| how many tests check each part, what they check, and, for a part nothing
| checks, one tap to ask for its tests.
*/

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->create();
    $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
        '.builder/project.md' => "# Project\n\nA shop for plans.\n",
        '.builder/capabilities/plans.md' => "---\ncapability: plans\nsummary: Customers choose what they pay for.\npaths: [app/Models/Plan.php]\n---\n# Plans\n",
        '.builder/capabilities/teams.md' => "---\ncapability: teams\nsummary: People work in teams.\npaths: [app/Models/Team.php]\n---\n# Teams\n",
        'app/Models/Plan.php' => "<?php\n",
    ]), draftNotes: false);
    app(ProjectRepository::class)->import($this->project);

    // Two tests run the plan code; none runs team code.
    TestObservation::create(['project_id' => $this->project->id, 'tests' => [
        ['id' => 'Tests\\Feature\\PlanTest::test_customers_pick_a_plan', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
        ['id' => 'Tests\\Feature\\PlanTest::test_plans_are_listed', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
    ], 'files' => ['app/Models/Plan.php' => [0, 1]]]);
});

it('shows what the tests check, and asks for tests for a part nothing checks', function () {
    $this->actingAs($this->owner);

    visit(route('projects.understanding.show', $this->project))
        ->assertSee('Checked by 2 tests')
        ->click('What the tests check')
        ->assertSee('Customers pick a plan')
        ->assertSee('Nothing checks this yet')
        ->click('@part-ask-tests')
        ->assertSee('Add tests that check Teams works as described');

    expect($this->project->featureRequests()->sole()->prompt)->toBe('Add tests that check Teams works as described');
});
