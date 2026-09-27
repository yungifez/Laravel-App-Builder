<?php

namespace Tests\Feature\Features;

use App\Context\ProjectNotes;
use App\Jobs\ExecuteRun;
use App\Models\User;
use App\Projects\DesignDirection;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class NewProjectTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Building the first version is a queued run; these tests stop there.
        Queue::fake();
    }

    public function test_an_owner_starts_a_new_app_from_the_template_with_one_answer()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create(['name' => 'Ada Owner']);

        $response = $this->actingAs($owner)->post(route('projects.new.store'), [
            'name' => 'Bright Cleaning',
            'purpose' => 'Cleaners see their jobs for the day, and customers book a clean online.',
        ]);

        $project = $owner->projects()->sole();
        $this->assertNull($project->notes_draft_status);

        // The owner's sentence is the first change, and they are taken to it.
        $first = $project->featureRequests()->sole();
        $this->assertSame('Make the first version: Cleaners see their jobs for the day, and customers book a clean online. Give it its own front page in place of the starter welcome page.', $first->prompt);
        $this->assertTrue($first->user->is($owner));
        $response->assertRedirect(route('projects.show', ['project' => $project, 'change' => $first->id]));
        Queue::assertPushed(ExecuteRun::class);

        $repository = app(ProjectRepository::class);
        $this->assertSame(['Import Bright Cleaning'], array_column($repository->log($project), 'subject'));
        $this->assertSame('Ada Owner', $repository->log($project)[0]['author']);
        $this->assertStringContainsString(
            'Cleaners see their jobs for the day, and customers book a clean online.',
            app(ProjectNotes::class)->files($project)['project.md'],
        );
        $this->assertNotContains('.builder/project.md', $repository->files($project, $repository->head($project)));
        $this->assertFileExists($repository->path($project).'/app/Models/Team.php');
    }

    public function test_the_app_is_called_by_the_name_the_owner_gave_it()
    {
        config(['builder.projects.template' => $this->makeProjectSource(['.env.example' => "APP_NAME=Laravel\nAPP_ENV=local\n"] + $this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.new.store'), ['name' => 'Bright "Cleaning"', 'purpose' => 'Book a clean.']);

        $repository = app(ProjectRepository::class);
        $project = $owner->projects()->sole();
        $this->assertSame("APP_NAME=\"Bright Cleaning\"\nAPP_ENV=local\n", $repository->show($project, $repository->head($project), '.env.example'));
        $this->assertSame('Name the app Bright Cleaning', $repository->log($project)[0]['subject']);
    }

    public function test_the_first_version_can_be_left_to_the_owner()
    {
        config([
            'builder.projects.template' => $this->makeProjectSource($this->laravelApp()),
            'builder.projects.first_version' => false,
        ]);
        $owner = User::factory()->create();

        $response = $this->actingAs($owner)->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.']);

        $project = $owner->projects()->sole();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame(0, $project->featureRequests()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_answer_replaces_what_the_template_says_the_app_is_for()
    {
        config(['builder.projects.template' => $this->makeProjectSource(['.builder/project.md' => "# Project\n\nA starter app.\n\n## Terms\n\n- A team is a group.\n"] + $this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.']);

        $repository = app(ProjectRepository::class);
        $project = $owner->projects()->sole();
        $notes = app(ProjectNotes::class)->files($project)['project.md'];
        $this->assertSame([], preg_grep('/^\.builder\//', $repository->files($project, $repository->head($project))));
        $this->assertStringContainsString('Plan the week.', $notes);
        $this->assertStringNotContainsString('A starter app.', $notes);
        $this->assertStringContainsString('- A team is a group.', $notes);
    }

    public function test_the_one_question_must_be_answered()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);

        $this->actingAs(User::factory()->create())
            ->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => ''])
            ->assertSessionHasErrors(['purpose' => 'Tell me in a sentence or two what your app is for.']);
    }

    public function test_starting_a_new_app_is_offered_only_when_the_template_is_in_place()
    {
        $owner = User::factory()->create();
        config(['builder.projects.template' => null]);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canStartNew', false));

        $this->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.'])
            ->assertSessionHasErrors(['name' => 'Starting a new app is not set up here.']);
        $this->assertSame(0, $owner->projects()->count());

        // A template that was never put in place is not offered either.
        config(['builder.projects.template' => '/srv/no-template-here']);
        $this->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canStartNew', false));

        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $this->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canStartNew', true));
    }

    public function test_the_look_the_owner_picks_sets_the_theme_and_the_design_contract()
    {
        config(['builder.projects.template' => $this->makeProjectSource([
            'resources/css/app.css' => ":root {\n    --primary: hsl(0 0% 9%);\n    --radius: 0.5rem;\n}\n\n.dark {\n    --primary: hsl(0 0% 98%);\n}\n\n@theme inline {\n    --font-sans: Instrument Sans, ui-sans-serif, sans-serif;\n}\n",
            'vite.config.ts' => "fonts: [bunny('Instrument Sans', { weights: [400, 500, 600] })],\n",
        ] + $this->laravelApp())]);
        $owner = User::factory()->create(['name' => 'Ada Owner']);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('designs.0.key', 'calm')->has('designs.0.colors.primary'));

        $this->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.', 'design' => 'calm'])
            ->assertSessionHasNoErrors();

        $repository = app(ProjectRepository::class);
        $project = $owner->projects()->sole();
        $log = $repository->log($project);
        $this->assertSame(['Use the Calm look', 'Import Acme'], array_column($log, 'subject'));
        $this->assertSame('Ada Owner', $log[0]['author']);

        $css = (string) $repository->show($project, $repository->head($project), 'resources/css/app.css');
        $this->assertStringContainsString('--primary: hsl(174 62% 24%);', $css);
        $this->assertStringContainsString('--primary: hsl(172 50% 52%);', $css);
        $this->assertStringContainsString('--radius: 0.75rem;', $css);
        $this->assertStringContainsString("--font-sans: 'Instrument Sans', ui-sans-serif", $css);

        $contract = app(ProjectNotes::class)->files($project)['design.md'];
        $this->assertStringContainsString('This is how Acme looks and behaves.', $contract);
        $this->assertStringContainsString('| `primary` | `hsl(174 62% 24%)` | `hsl(172 50% 52%)` |', $contract);
        $this->assertStringNotContainsString('{{', $contract);
        $this->assertDoesNotMatchRegularExpression('/builder|control plane|agent/i', $contract);
        $this->assertStringContainsString('Plan the week.', app(ProjectNotes::class)->files($project)['project.md']);
    }

    public function test_every_look_offered_is_complete()
    {
        $looks = File::glob(resource_path('designs').'/*.json');

        $this->assertNotEmpty($looks);

        foreach ($looks as $path) {
            $look = DesignDirection::load($path);
            $this->assertNotNull($look, basename($path));
            $this->assertSame([], array_diff(array_keys($look->light), array_keys($look->dark)), basename($path));
            $this->assertArrayHasKey('primary', $look->light, basename($path));
        }
    }

    public function test_a_look_that_is_not_offered_is_refused()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.', 'design' => '../secrets'])
            ->assertSessionHasErrors('design');
        $this->assertSame(0, $owner->projects()->count());
    }
}
