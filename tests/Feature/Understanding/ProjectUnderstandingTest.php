<?php

namespace Tests\Feature\Understanding;

use App\Actions\Projects\CreateProject;
use App\Context\ProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\TestObservation;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ProjectUnderstandingTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const PROJECT_NOTES = <<<'MARKDOWN'
    # Project

    A shop for **plans**.

    ## People

    - Customers buy plans.

    ## Engineering direction

    - Use Actions for changes.

    MARKDOWN;

    protected const PLANS_NOTES = <<<'MARKDOWN'
    ---
    capability: plans
    summary: Customers pick a plan.
    paths:
        - app/Models/Plan.php
        - app/Billing/*
    behaviors:
        - key: pick-plan
          name: Pick a plan
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

    ## Rules

    - Every customer sees the same plans.

    MARKDOWN;

    protected const TEAMS_NOTES = <<<'MARKDOWN'
    ---
    capability: teams
    paths: [app/Models/Team.php, tests/Feature/TeamTest.php]
    ---
    # Teams

    MARKDOWN;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            '.builder/project.md' => self::PROJECT_NOTES,
            '.builder/capabilities/plans.md' => self::PLANS_NOTES,
            '.builder/capabilities/teams.md' => self::TEAMS_NOTES,
            'app/Models/Plan.php' => "<?php\n",
            'app/Http/Controllers/PlanController.php' => "<?php\n",
            'app/Providers/AppServiceProvider.php' => "<?php\n",
            'tests/Feature/TeamTest.php' => "<?php\n",
        ]));
        $this->repository->import($this->project);
    }

    public function test_the_owner_sees_the_notes_in_their_own_words()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Understanding')
                ->where('revision', $this->version())
                ->where('about.introduction', 'A shop for **plans**.')
                ->where('about.sections', [['heading' => 'People', 'body' => '- Customers buy plans.']])
                ->where('guidance', '- Use Actions for changes.')
                ->has('areas', 2)
                ->where('areas.0.name', 'Plans')
                ->where('areas.0.summary', 'Customers pick a plan.')
                ->where('areas.0.behaviors', ['Pick a plan'])
                ->where('areas.0.rules', ['Every customer sees the same plans.'])
                ->where('areas.0.connections.0.name', 'Teams')
                ->where('areas.0.connections.0.reason', 'Each team has one plan.')
                ->where('areas.0.connections.1.name', 'Invoices')
                ->where('areas.0.tested', false)
                ->where('areas.1.tested', true)
                // No test run has been mapped yet, so nothing is counted.
                ->where('areas.0.checked_by', null)
                ->where('areas.0.checks', [])
                ->missing('check'));
    }

    public function test_the_owner_sees_how_many_problems_were_fixed_before_they_saw_their_changes()
    {
        $sentBack = function (array $attributes, string ...$reasons) {
            $run = Run::factory()->for(FeatureRequest::factory()->generated()->for($this->project)->create($attributes))->create();

            foreach ($reasons as $reason) {
                $run->recordEvent('status', ['from' => 'verifying', 'to' => 'implementing', 'reason' => $reason]);
            }

            // Moving forward is not a problem caught.
            $run->recordEvent('status', ['from' => 'implementing', 'to' => 'verifying']);
        };
        $sentBack(['accepted_at' => now()], 'verification_failed', 'review_findings');
        $sentBack(['accepted_at' => now()], 'verification_failed');
        // Only changes still in the app count.
        $sentBack(['accepted_at' => now(), 'reverted_at' => now()], 'verification_failed');
        $sentBack([], 'review_findings');

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('caught', 3)
                ->where('kept', 2));
    }

    public function test_each_part_says_how_many_of_the_apps_tests_run_its_code()
    {
        // Two tests run the plan model; no test runs team code.
        TestObservation::create(['project_id' => $this->project->id, 'tests' => [
            ['id' => 'Tests\\Feature\\PlanTest::test_customers_pick_a_plan', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
            ['id' => 'Tests\\Feature\\PlanTest::test_plans_are_listed', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
        ], 'files' => ['app/Models/Plan.php' => [0, 1]]]);

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('areas.0.key', 'plans')
                ->where('areas.0.checked_by', 2)
                // What they check, in the tests' own words.
                ->where('areas.0.checks', ['Customers pick a plan', 'Plans are listed'])
                ->where('areas.1.key', 'teams')
                ->where('areas.1.checked_by', 0)
                ->where('areas.1.checks', []));
    }

    public function test_a_link_the_notes_and_the_tests_both_show_is_said_once_with_both_reasons()
    {
        // Two team tests run the plan model: the tests link plans to teams,
        // as the notes already do.
        TestObservation::create(['project_id' => $this->project->id, 'tests' => [
            ['id' => 'Tests\\Feature\\TeamTest::test_teams_have_a_plan', 'file' => 'tests/Feature/TeamTest.php', 'groups' => []],
            ['id' => 'Tests\\Feature\\TeamTest::test_teams_change_plan', 'file' => 'tests/Feature/TeamTest.php', 'groups' => []],
        ], 'files' => ['app/Models/Plan.php' => [0, 1]]]);

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->has('areas.0.connections', 2)
                ->where('areas.0.connections.0', ['to' => 'teams', 'name' => 'Teams', 'reason' => 'Each team has one plan. Seen when your app\'s tests ran.', 'strength' => 'strong'])
                ->where('areas.0.connections.1.name', 'Invoices'));
    }

    public function test_the_quick_check_lists_the_gaps_between_the_notes_and_the_code()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('check', fn (Assert $page) => $page
                ->where('check', [
                    ['title' => 'The notes on "Plans" point to files that are not in the app.', 'details' => ['app/Billing/*']],
                    ['title' => '"Plans" says it is connected to something the notes do not describe.', 'details' => ['invoices']],
                    ['title' => 'Nothing checks "Plans" automatically.', 'details' => ['No test for it runs with the checks.']],
                    ['title' => 'Some parts of the app are not described in any notes.', 'details' => ['app/Http/Controllers/PlanController.php']],
                ])));
    }

    public function test_the_quick_check_says_first_when_secret_settings_are_kept_in_the_app()
    {
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), [
            '.env' => "APP_KEY=base64:secret\n",
            '.env.example' => "APP_KEY=\n",
        ], 'Add settings', null);

        $this->actingAs($this->owner)
            ->get(route('projects.understanding.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('check', fn (Assert $page) => $page
                ->where('check.0', ['title' => 'Secret settings are saved in the app\'s code, where anyone with the code can read them.', 'details' => ['.env']])));
    }

    public function test_the_owner_changes_what_the_app_is_for_and_it_is_saved_outside_the_app()
    {
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), [
                'part' => 'introduction',
                'body' => "A shop where teams buy plans.\n",
                'revision' => $this->version(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertSame(
            "# Project\n\nA shop where teams buy plans.\n\n## People\n\n- Customers buy plans.\n\n## Engineering direction\n\n- Use Actions for changes.\n",
            $this->notes('project.md'),
        );
    }

    public function test_the_owner_edits_an_areas_summary_and_rules_without_touching_the_rest()
    {
        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), [
                'part' => 'summary:plans',
                'body' => 'Customers pick one of three plans.',
                'revision' => $this->version(),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), [
                'part' => 'rules:plans',
                'body' => "Every customer sees the same plans.\n\n- Prices include tax.",
                'revision' => $this->version(),
            ])
            ->assertSessionHasNoErrors();

        $notes = $this->notes('capabilities/plans.md');

        $this->assertSame(str_replace(
            ["summary: Customers pick a plan.\n", "- Every customer sees the same plans.\n"],
            ["summary: Customers pick one of three plans.\n", "- Every customer sees the same plans.\n- Prices include tax.\n"],
            self::PLANS_NOTES,
        ), $notes);
    }

    public function test_the_developer_guidance_can_be_added_when_there_is_none()
    {
        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), [
                'part' => 'section:Engineering direction',
                'body' => '- External services go through adapters.',
                'revision' => $this->version(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringEndsWith(
            "## Engineering direction\n\n- External services go through adapters.\n",
            $this->notes('project.md'),
        );
    }

    public function test_an_edit_made_on_an_old_version_is_refused()
    {
        $old = $this->version();

        $this->actingAs($this->owner)->put(route('projects.understanding.update', $this->project), ['part' => 'introduction', 'body' => 'First.', 'revision' => $old]);

        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), ['part' => 'introduction', 'body' => 'Second.', 'revision' => $old])
            ->assertSessionHasErrors(['body' => 'The notes changed while you were editing. Try again on the updated version.']);
    }

    public function test_edits_that_would_break_the_notes_or_change_nothing_are_refused()
    {
        $head = $this->version();

        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), ['part' => 'summary:plans', 'body' => str_repeat('a', 501), 'revision' => $head])
            ->assertSessionHasErrors('body');

        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), ['part' => 'introduction', 'body' => 'A shop for **plans**.', 'revision' => $head])
            ->assertSessionHasErrors(['body' => 'Nothing changed.']);

        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), ['part' => 'rules:billing', 'body' => 'x', 'revision' => $head])
            ->assertSessionHasErrors(['body' => 'These notes no longer exist. Reload the page.']);

        $this->actingAs($this->owner)
            ->put(route('projects.understanding.update', $this->project), ['part' => 'file:../../etc', 'body' => 'x', 'revision' => $head])
            ->assertSessionHasErrors('part');

        $this->assertSame($head, $this->version());
    }

    public function test_other_people_cannot_see_or_edit_the_notes()
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('projects.understanding.show', $this->project))->assertForbidden();
        $this->actingAs($stranger)
            ->put(route('projects.understanding.update', $this->project), ['part' => 'introduction', 'body' => 'Mine.', 'revision' => $this->version()])
            ->assertForbidden();
    }

    /**
     * Get the version of the notes the page shows.
     */
    protected function version(): string
    {
        return app(ProjectNotes::class)->version($this->project);
    }

    /**
     * Get one of the saved notes.
     */
    protected function notes(string $path): string
    {
        return app(ProjectNotes::class)->files($this->project)[$path];
    }
}
