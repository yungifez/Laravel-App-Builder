<?php

namespace Tests\Feature\Features;

use App\Actions\Projects\StartProjectFromTemplate;
use App\Context\ProjectNotes;
use App\Jobs\ExecuteRun;
use App\Models\Project;
use App\Models\User;
use App\Projects\DesignDirection;
use App\Projects\ProjectRepository;
use App\Projects\Starter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
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
        $this->assertTrue($project->started_here);
        $this->assertFalse($project->mayBeInUse());

        // The owner's sentence is the first change, and they are taken to it.
        $first = $project->featureRequests()->sole();
        $this->assertSame('Make the first version: Cleaners see their jobs for the day, and customers book a clean online.', $first->prompt);
        // The builder, not the owner, asks for the app's own front page.
        $this->assertSame("Make the first version: Cleaners see their jobs for the day, and customers book a clean online.\n\nGive it its own front page in place of the starter welcome page.", $first->instructions());
        $this->assertTrue($first->user->is($owner));
        $response->assertRedirect(route('projects.show', ['project' => $project, 'change' => $first->uuid]));
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

    public function test_a_second_app_with_the_same_name_gets_a_number_so_the_two_can_be_told_apart()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create();
        Project::factory()->for($owner, 'owner')->create(['name' => 'Bright Cleaning']);
        Project::factory()->create(['name' => 'Bright Cleaning 2']);

        $this->actingAs($owner)->post(route('projects.new.store'), ['name' => 'bright cleaning', 'purpose' => 'Book a clean.']);
        $this->actingAs($owner)->post(route('projects.new.store'), ['name' => 'Bright Cleaning', 'purpose' => 'Book a clean.']);

        $this->assertSame(['Bright Cleaning', 'bright cleaning 2', 'Bright Cleaning 3'], $owner->projects()->orderBy('id')->pluck('name')->all());
    }

    public function test_what_the_owner_kept_from_a_starter_goes_with_the_first_version()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.new.store'), [
            'name' => 'Studio Classes',
            'purpose' => 'Members book a place in a class.',
            'includes' => ['A timetable of upcoming classes', ' ', 'Trainers see who is booked'],
        ])->assertRedirect();

        $this->assertSame(
            "Make the first version: Members book a place in a class.\n\nIt includes:\n- A timetable of upcoming classes\n- Trainers see who is booked",
            $owner->projects()->sole()->featureRequests()->sole()->prompt,
        );
        $this->assertStringEndsWith(
            "- Trainers see who is booked\n\nGive it its own front page in place of the starter welcome page.",
            $owner->projects()->sole()->featureRequests()->sole()->instructions(),
        );
        // What the app is for stays the owner's sentence.
        $this->assertStringNotContainsString('timetable', app(ProjectNotes::class)->files($owner->projects()->sole())['project.md']);
    }

    public function test_the_owner_is_offered_starters_each_with_a_look_that_exists()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);

        $this->actingAs(User::factory()->create())
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('starters.0.key', 'cleaning')
                ->where('starters.0.name', 'Bright Cleaning')
                ->has('starters.0.includes', 4));

        $looks = array_map(fn (DesignDirection $look) => $look->key, DesignDirection::all());

        foreach (Starter::all() as $starter) {
            $this->assertContains($starter->design, $looks, $starter->key);
            $this->assertNotEmpty($starter->includes, $starter->key);
        }
    }

    public function test_a_starter_file_that_is_not_complete_is_skipped()
    {
        $folder = sys_get_temp_dir().'/builder-starters-'.uniqid();
        File::ensureDirectoryExists($folder);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($folder));
        File::put("{$folder}/broken.json", '{"name": "Broken"');
        File::put("{$folder}/partial.json", '{"name": "Partial"}');
        File::put("{$folder}/good.json", '{"name": "Good", "purpose": "Do good.", "includes": ["One thing", 3]}');
        config(['builder.projects.starters' => $folder]);

        $this->assertSame([['key' => 'good', 'name' => 'Good', 'purpose' => 'Do good.', 'design' => null, 'includes' => ['One thing']]], array_map(fn (Starter $starter) => $starter->toArray(), Starter::all()));
    }

    public function test_a_sketch_the_owner_attaches_goes_with_the_first_version()
    {
        Storage::fake(config('builder.construction.images.disk'));
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.new.store'), [
            'name' => 'Corner Shop',
            'purpose' => 'Customers order online and collect in store.',
            'images' => [UploadedFile::fake()->image('sketch.png')],
        ])->assertRedirect();

        $images = $owner->projects()->sole()->featureRequests()->sole()->images;
        $this->assertSame('sketch.png', $images[0]['name']);
        Storage::disk(config('builder.construction.images.disk'))->assertExists($images[0]['path']);

        $this->post(route('projects.new.store'), ['name' => 'Shop', 'purpose' => 'Sell things.', 'images' => [UploadedFile::fake()->create('notes.pdf')]])
            ->assertSessionHasErrors('images.0');
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

    public function test_an_app_the_owner_did_not_name_is_named_from_its_idea()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('projects.new.store'), ['name' => '  ', 'purpose' => "A small bakery takes cake orders online and staff see today's orders."])
            ->assertSessionHasNoErrors();

        $project = $owner->projects()->sole();
        $this->assertSame('Small Bakery', $project->name);
        $this->assertSame(1, $project->featureRequests()->count());
    }

    public function test_an_idea_with_no_name_in_it_starts_as_my_app_numbered_after_the_first()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.new.store'), ['purpose' => 'I want to sell things.'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('projects.new.store'), ['name' => '', 'purpose' => 'Please make it for us!'])->assertSessionHasNoErrors();

        $this->assertSame(['My app', 'My app 2'], $owner->projects()->orderBy('id')->pluck('name')->all());
    }

    public function test_a_name_is_taken_from_the_first_words_of_an_idea_before_any_verb()
    {
        $this->assertSame('Cleaners', StartProjectFromTemplate::nameFor('My cleaners see their jobs for the day, and customers book a clean online.'));
        $this->assertSame('Yoga Studio', StartProjectFromTemplate::nameFor('An app for my yoga studio where members book classes'));
        $this->assertSame('ISBN Shelf', StartProjectFromTemplate::nameFor('ISBN shelf: scan a book and keep it'));
        $this->assertSame('Dog Walkers Weekly Rota', StartProjectFromTemplate::nameFor('dog walkers weekly rota planner that sends reminders'));
        $this->assertSame('My app', StartProjectFromTemplate::nameFor('Sells things.'));
    }

    public function test_a_name_too_long_is_still_refused()
    {
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('projects.new.store'), ['name' => str_repeat('a', 256), 'purpose' => 'Plan the week.'])
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $owner->projects()->count());
    }

    public function test_starting_a_new_app_is_offered_only_when_the_template_is_in_place()
    {
        $owner = User::factory()->create();
        config(['builder.projects.template' => null]);
        Exceptions::fake();

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canStartNew', false));

        $this->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.'])
            ->assertSessionHasErrors(['name' => 'This is our fault: starting a new app is switched off here right now. Nothing was saved. Please try again later, or tell us on the Contact page.']);
        $this->assertSame(0, $owner->projects()->count());
        // It is ours to fix, so we hear of it.
        Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), 'builder.projects.template'));

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
