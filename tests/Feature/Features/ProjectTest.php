<?php

namespace Tests\Feature\Features;

use App\Actions\Previews\DescribeProjectPreview;
use App\Actions\Previews\ReadPreviewEmails;
use App\Actions\Projects\SummarizeChanges;
use App\Actions\Projects\SummarizeProjectTelemetry;
use App\Actions\Publishing\DescribeUnpublished;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\TestObservation;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }

    public function test_email_polls_do_not_load_unrequested_project_data()
    {
        $project = Project::factory()->create();
        $this->mock(SummarizeChanges::class)->shouldNotReceive('handle');
        $this->mock(SummarizeProjectTelemetry::class)->shouldNotReceive('handle');
        $this->mock(DescribeProjectPreview::class)->shouldNotReceive('handle');
        $this->mock(DescribeUnpublished::class)->shouldNotReceive('handle');
        $this->mock(ReadPreviewEmails::class)->shouldReceive('handle')->once()->andReturn([]);
        Process::fake();
        DB::enableQueryLog();

        try {
            $this->actingAs($project->owner)->get(route('projects.show', $project), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
                'X-Inertia-Partial-Component' => 'projects/Show',
                'X-Inertia-Partial-Data' => 'emails',
            ])->assertOk()->assertJsonPath('props.emails', [])->assertJsonMissingPath('props.telemetry');

            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/from "(?:visual_edits|experiments|deployments|test_observations|feature_requests|run_events)"/', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        Process::assertNothingRan();
    }

    public function test_users_see_only_their_own_projects()
    {
        $user = User::factory()->create();
        Project::factory()->for($user, 'owner')->create(['name' => 'Mine']);
        Project::factory()->create(['name' => 'Theirs']);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Index')
                ->has('projects', 1)
                ->where('projects.0.name', 'Mine'));
    }

    public function test_users_can_add_a_project_which_is_imported_into_its_repository()
    {
        $user = User::factory()->create();
        $source = $this->makeProjectSource(['.env' => "APP_KEY=secret\n", 'vendor/autoload.php' => "<?php\n", '.builder/project.md' => "# Project\n"] + $this->laravelApp());

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Acme',
            'source_path' => $source,
        ]);

        $project = $user->projects()->sole();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame($source, $project->source_path);

        $repository = app(ProjectRepository::class);
        $this->assertTrue($repository->exists($project));
        $this->assertSame('Import Acme', $repository->log($project)[0]['subject']);
        $this->assertFileExists($repository->path($project).'/app/Models/Team.php');
        $this->assertFileDoesNotExist($repository->path($project).'/.env');
        $this->assertFileDoesNotExist($repository->path($project).'/vendor/autoload.php');
    }

    public function test_a_source_that_is_not_a_directory_is_refused()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('projects.store'), ['name' => 'Acme', 'source_path' => '/srv/does-not-exist'])
            ->assertSessionHasErrors('source_path');

        $this->assertSame(0, $user->projects()->count());
        $this->assertFalse(File::exists(config('builder.projects.root')));
    }

    public function test_a_project_needs_a_name_and_source_path()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('projects.store'), [])
            ->assertSessionHasErrors(['name', 'source_path']);
    }

    public function test_users_cannot_view_someone_elses_project()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', Project::factory()->create()))
            ->assertForbidden();
    }

    public function test_the_workspace_says_how_many_tests_guard_the_app()
    {
        $project = Project::factory()->create();

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('project.tests', null));

        TestObservation::create(['project_id' => $project->id, 'tests' => [
            ['id' => 'Tests\\Feature\\PlanTest::test_customers_pick_a_plan', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
            ['id' => 'Tests\\Feature\\PlanTest::test_plans_are_listed', 'file' => 'tests/Feature/PlanTest.php', 'groups' => []],
        ], 'files' => []]);

        $this->actingAs($project->owner)
            ->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('project.tests', 2));
    }
}
