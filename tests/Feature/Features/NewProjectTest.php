<?php

namespace Tests\Feature\Features;

use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class NewProjectTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    public function test_an_owner_starts_a_new_app_from_the_template_with_one_answer()
    {
        Queue::fake();
        config(['builder.projects.template' => $this->makeProjectSource($this->laravelApp())]);
        $owner = User::factory()->create(['name' => 'Ada Owner']);

        $response = $this->actingAs($owner)->post(route('projects.new.store'), [
            'name' => 'Bright Cleaning',
            'purpose' => 'Cleaners see their jobs for the day, and customers book a clean online.',
        ]);

        $project = $owner->projects()->sole();
        $response->assertRedirect(route('projects.show', $project));
        $this->assertNull($project->notes_draft_status);
        Queue::assertNothingPushed();

        $repository = app(ProjectRepository::class);
        $this->assertSame(['Describe what the app is for', 'Import Bright Cleaning'], array_column($repository->log($project), 'subject'));
        $this->assertSame('Ada Owner', $repository->log($project)[0]['author']);
        $this->assertStringContainsString(
            'Cleaners see their jobs for the day, and customers book a clean online.',
            (string) $repository->show($project, $repository->head($project), '.builder/project.md'),
        );
        $this->assertFileExists($repository->path($project).'/app/Models/Team.php');
    }

    public function test_the_answer_replaces_what_the_template_says_the_app_is_for()
    {
        config(['builder.projects.template' => $this->makeProjectSource(['.builder/project.md' => "# Project\n\nA starter app.\n\n## Terms\n\n- A team is a group.\n"] + $this->laravelApp())]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.']);

        $repository = app(ProjectRepository::class);
        $project = $owner->projects()->sole();
        $notes = (string) $repository->show($project, $repository->head($project), '.builder/project.md');
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

    public function test_starting_a_new_app_is_offered_only_when_a_template_is_set()
    {
        $owner = User::factory()->create();
        config(['builder.projects.template' => null]);

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canStartNew', false));

        $this->post(route('projects.new.store'), ['name' => 'Acme', 'purpose' => 'Plan the week.'])
            ->assertSessionHasErrors(['name' => 'Starting a new app is not set up here.']);
        $this->assertSame(0, $owner->projects()->count());

        config(['builder.projects.template' => '/srv/template']);
        $this->get(route('projects.index'))
            ->assertInertia(fn (Assert $page) => $page->where('canStartNew', true));
    }
}
