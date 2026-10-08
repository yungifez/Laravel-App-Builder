<?php

namespace Tests\Feature\Understanding;

use App\Actions\Context\ReadProjectContext;
use App\Ai\Agents\NotesDrafter;
use App\Enums\NotesDraftStatus;
use App\Features\SpendPause;
use App\Jobs\DraftProjectNotes;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\TestObservation;
use App\Models\User;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class ExploreAppTest extends TestCase
{
    use FakesWorkspaces;
    use PreparesRuns;
    use RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    /**
     * The command that runs the app's tests with code coverage.
     *
     * @var list<string>
     */
    protected array $map = ['sh', '-c', 'make the test map'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        config([
            'builder.verification.workspace_driver' => 'fake',
            'builder.verification.setup' => [],
            'builder.verification.traces.enabled' => false,
            'builder.verification.test_map' => [...config('builder.verification.test_map'), 'command' => $this->map, 'report' => 'covered.txt', 'listing' => 'tests.xml'],
            'builder.models.planner.model' => 'test-planner',
            'builder.prices' => ['test-planner' => ['input' => 3, 'output' => 15]],
        ]);
    }

    public function test_an_imported_app_is_explored_only_when_the_owner_chooses_it_after_reading_the_cost()
    {
        Queue::fake();
        config(['operations.operators' => [$this->owner->email]]);

        $this->actingAs($this->owner)->post(route('projects.store'), [
            'name' => 'Acme',
            'source_path' => $this->makeProjectSource($this->laravelApp()),
        ]);

        $project = $this->owner->projects()->sole();
        $this->assertNull($project->notes_draft_status);
        Queue::assertNothingPushed();

        $this->get(route('projects.understanding.show', $project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('draft', null)
                ->where('exploration.tokens', fn (int $tokens) => $tokens >= 5_000 && $tokens % 1000 === 0)
                ->where('exploration.cost_usd', fn (float $cost) => $cost > 0));

        $this->post(route('projects.exploration.store', $project))->assertRedirect();
        $this->post(route('projects.exploration.store', $project))->assertRedirect();

        $this->assertSame(NotesDraftStatus::Drafting, $project->refresh()->notes_draft_status);
        // A second click while it explores does not pay twice.
        Queue::assertPushed(DraftProjectNotes::class, 1);

        $this->get(route('projects.understanding.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('draft.status', 'drafting')->where('exploration', null));
    }

    public function test_an_app_that_has_notes_is_not_offered_exploring_and_others_cannot_start_it()
    {
        Queue::fake();
        $described = $this->project(['.builder/project.md' => "# Project\n"]);
        $other = $this->project();

        $this->actingAs($this->owner)->get(route('projects.understanding.show', $described))
            ->assertInertia(fn (Assert $page) => $page->where('exploration', null));

        $this->actingAs(User::factory()->create())->post(route('projects.exploration.store', $other))->assertForbidden();
        $this->actingAs($this->owner)->post(route('projects.exploration.store', $described));

        Queue::assertNothingPushed();
    }

    public function test_exploring_waits_once_todays_ai_spend_reaches_the_daily_limit()
    {
        Queue::fake();
        config(['builder.construction.budgets.daily_usd' => 10]);
        Run::factory()->create()->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 10.5]);
        $project = $this->project();

        $this->actingAs($this->owner)
            ->post(route('projects.exploration.store', $project))
            ->assertSessionHas('errors', fn ($errors) => $errors->first('explore') === SpendPause::message());

        $this->assertNull($project->refresh()->notes_draft_status);
        Queue::assertNothingPushed();
    }

    public function test_exploring_waits_once_the_owner_used_this_months_plan()
    {
        Queue::fake();
        config(['billing.plans.free.monthly_usd' => 5]);
        $project = $this->project();
        Run::factory()->for(FeatureRequest::factory()->for($project))->create()
            ->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 5.5]);

        $this->actingAs($this->owner)
            ->post(route('projects.exploration.store', $project))
            ->assertSessionHas('errors', fn ($errors) => str_starts_with((string) $errors->first('explore'), 'You have used all the AI use your plan includes this month.'));

        $this->assertNull($project->refresh()->notes_draft_status);
        Queue::assertNothingPushed();
    }

    public function test_exploring_runs_the_apps_tests_and_words_only_what_the_facts_back()
    {
        $project = $this->exploring([
            'app/Policies/TeamPolicy.php' => "<?php\n",
            'app/Mail/TeamInvite.php' => "<?php\n",
            'database/migrations/2026_01_01_000000_create_teams_table.php' => "<?php\n",
            'tests/Feature/ArchiveTest.php' => "<?php\n",
        ]);
        $this->runsTests();
        NotesDrafter::fake([[
            'purpose' => 'A place where teams plan their work.',
            'areas' => [[
                'key' => 'teams',
                'name' => 'Teams',
                'summary' => 'Make and archive teams.',
                'paths' => ['app/Models/Team.php', 'app/Policies/*', 'tests/Feature/ArchiveTest.php'],
                'behaviors' => [['key' => 'archive', 'name' => 'Archive a team']],
                'rules' => [
                    ['rule' => 'Only owners can archive a team.', 'source' => 'app/Policies/TeamPolicy.php:12'],
                    ['rule' => 'A team has at most ten members.', 'source' => 'app/Rules/Nowhere.php'],
                    ['rule' => 'Teams are free.', 'source' => ''],
                ],
            ]],
        ]]);

        app()->call([new DraftProjectNotes($project), 'handle']);

        $project->refresh();
        $this->assertSame(NotesDraftStatus::Ready, $project->notes_draft_status);
        $teams = $project->notes_draft['areas'][0];
        // A rule that does not name a file of the app that enforces it is a guess.
        $this->assertSame(['Only owners can archive a team.'], $teams['rules']);
        // What backs the area without a model: the tests that ran its code.
        $this->assertSame(1, $teams['tests']);

        NotesDrafter::assertPrompted(fn ($prompt) => $prompt->contains('Tables: teams')
            && $prompt->contains('Emails: TeamInvite')
            && $prompt->contains('Policies (who may do what): TeamPolicy')
            && $prompt->contains('- GET /teams → TeamController::index [auth]')
            && $prompt->contains('- tests/Feature/ArchiveTest.php: Owners archive teams')
            && $prompt->contains('ran: app/Models/Team.php'));

        // The page shows what checks each part from now on.
        $observation = TestObservation::query()->where('project_id', $project->id)->sole();
        $this->assertNull($observation->feature_request_id);
        $this->assertSame(['app/Models/Team.php'], array_keys($observation->files));
        $this->assertCount(1, $this->driver->destroyed);
    }

    public function test_an_app_whose_tests_do_not_run_is_still_described_from_its_code_and_says_so()
    {
        $project = $this->exploring();
        $this->driver->onExec = fn () => new CommandResult(exitCode: 1, output: '', errorOutput: 'failed', durationMs: 5);
        NotesDrafter::fake([[
            'purpose' => 'A place where teams plan their work.',
            'areas' => [['key' => 'teams', 'name' => 'Teams', 'summary' => '', 'paths' => ['app/Models/*'], 'behaviors' => [], 'rules' => []]],
        ]]);

        app()->call([new DraftProjectNotes($project), 'handle']);

        $this->assertNull($project->refresh()->notes_draft['areas'][0]['tests']);
        NotesDrafter::assertPrompted(fn ($prompt) => $prompt->contains("The app's tests did not all pass, so what they touch is not known."));

        $this->actingAs($this->owner)->get(route('projects.understanding.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('draft.areas.0.tests', null)->where('draft.areas.0.pages', [])->where('draft.areas.0.explored', true));
    }

    public function test_only_the_parts_the_owner_ticked_are_kept()
    {
        $project = $this->project();
        $project->update(['notes_draft_status' => NotesDraftStatus::Ready, 'notes_draft' => [
            'purpose' => 'A place where teams plan their work.',
            'areas' => [
                ['key' => 'teams', 'name' => 'Teams', 'summary' => 'Make teams.', 'paths' => ['app/Models/Team.php'], 'behaviors' => [], 'rules' => [], 'tests' => 2, 'pages' => ['GET /teams']],
                ['key' => 'billing', 'name' => 'Billing', 'summary' => 'Pay for plans.', 'paths' => ['config/*'], 'behaviors' => [], 'rules' => [], 'tests' => 0, 'pages' => []],
            ],
        ]]);

        $this->actingAs($this->owner)
            ->post(route('projects.notes-draft.store', $project), ['purpose' => false, 'areas' => []])
            ->assertSessionHasErrors(['draft' => 'Tick the parts that are right first.']);

        $this->post(route('projects.notes-draft.store', $project), ['purpose' => false, 'areas' => ['teams']])
            ->assertSessionHasNoErrors();

        $context = app(ReadProjectContext::class)->current($project);
        $this->assertSame(['teams'], array_keys($context->capabilities));
        // The purpose was not ticked, so it is not written as the owner's.
        $this->assertStringNotContainsString('teams plan their work', (string) $context->project);
    }

    /**
     * Make an imported app without notes.
     *
     * @param  array<string, string>  $files
     */
    protected function project(array $files = []): Project
    {
        $project = Project::factory()->for($this->owner, 'owner')->create(['source_path' => $this->makeProjectSource($files + $this->laravelApp())]);
        app(ProjectRepository::class)->import($project);

        return $project;
    }

    /**
     * Make an imported app the owner chose to explore.
     *
     * @param  array<string, string>  $files
     */
    protected function exploring(array $files = []): Project
    {
        $project = $this->project($files);
        $project->update(['notes_draft_status' => NotesDraftStatus::Drafting]);

        return $project;
    }

    /**
     * Let the workspace list the app's routes and run its one test, which
     * runs the Team model.
     */
    protected function runsTests(): void
    {
        $this->driver->onExec = function (string $workspace, array $command) {
            if (str_contains(implode(' ', $command), 'route:list')) {
                $this->driver->files["{$workspace}:storage/logs/explore-routes.json"] = json_encode([
                    ['method' => 'GET|HEAD', 'uri' => 'teams', 'action' => 'App\Http\Controllers\TeamController@index', 'middleware' => ['web', 'auth']],
                ]);
            }

            if ($command === $this->map) {
                $this->driver->files["{$workspace}:covered.txt"] = implode("\n", [
                    '/workspace',
                    '<project source="/workspace/app"',
                    '<file name="Team.php" path="/Models"',
                    '<line nr="3"',
                    'covered by="Tests\Feature\ArchiveTest::test_owners_archive_teams"',
                ]);
                $this->driver->files["{$workspace}:tests.xml"] = '<?xml version="1.0"?><testSuite xmlns="https://xml.phpunit.de/testSuite"><tests><testClass name="Tests\Feature\ArchiveTest" file="/workspace/tests/Feature/ArchiveTest.php"><testMethod id="Tests\Feature\ArchiveTest::test_owners_archive_teams" name="test_owners_archive_teams"/></testClass></tests></testSuite>';
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }
}
