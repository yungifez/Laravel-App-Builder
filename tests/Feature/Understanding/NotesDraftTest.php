<?php

namespace Tests\Feature\Understanding;

use App\Actions\Context\ReadProjectContext;
use App\Ai\Agents\NotesDrafter;
use App\Context\ProjectNotes;
use App\Enums\NotesDraftStatus;
use App\Jobs\DraftProjectNotes;
use App\Models\Project;
use App\Models\User;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class NotesDraftTest extends TestCase
{
    use PreparesRuns;
    use RefreshDatabase;

    protected ProjectRepository $repository;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
    }

    public function test_importing_an_app_without_notes_starts_a_draft_and_shows_it()
    {
        Queue::fake();

        $response = $this->actingAs($this->owner)->post(route('projects.store'), [
            'name' => 'Acme',
            'source_path' => $this->makeProjectSource($this->laravelApp()),
        ]);

        $project = $this->owner->projects()->sole();
        $response->assertRedirect(route('projects.understanding.show', $project));
        $this->assertSame(NotesDraftStatus::Drafting, $project->notes_draft_status);
        Queue::assertPushed(DraftProjectNotes::class, fn (DraftProjectNotes $job) => $job->project->is($project));

        $this->get(route('projects.understanding.show', $project))
            ->assertInertia(fn (Assert $page) => $page->where('draft.status', 'drafting'));
    }

    public function test_importing_an_app_that_has_notes_does_not_draft()
    {
        Queue::fake();

        $this->actingAs($this->owner)->post(route('projects.store'), [
            'name' => 'Acme',
            'source_path' => $this->makeProjectSource(['.builder/project.md' => "# Project\n"] + $this->laravelApp()),
        ])->assertRedirect(route('projects.show', $this->owner->projects()->sole()));

        $this->assertNull($this->owner->projects()->sole()->notes_draft_status);
        Queue::assertNothingPushed();
    }

    public function test_any_laravel_app_is_imported_whatever_its_screens_are_made_with()
    {
        Queue::fake();
        $noArtisan = $this->makeProjectSource(array_diff_key($this->laravelApp(), ['artisan' => true]));
        $noLaravel = $this->makeProjectSource(['composer.json' => json_encode(['require' => ['symfony/console' => '^7']])] + $this->laravelApp());
        $livewire = $this->makeProjectSource(['composer.json' => json_encode(['require' => ['laravel/framework' => '^13', 'livewire/livewire' => '^4']])] + array_diff_key($this->laravelApp(), ['package.json' => true]));

        $this->actingAs($this->owner)
            ->post(route('projects.store'), ['name' => 'Acme', 'source_path' => $noArtisan])
            ->assertSessionHasErrors(['source_path' => 'This folder is not a Laravel app: it has no artisan file.']);

        $this->post(route('projects.store'), ['name' => 'Acme', 'source_path' => $noLaravel])
            ->assertSessionHasErrors(['source_path' => 'I can only work on Laravel apps. This app does not use laravel/framework.']);

        $this->assertSame(0, $this->owner->projects()->count());

        // A Livewire app with no package.json is a Laravel app too.
        $this->post(route('projects.store'), ['name' => 'School', 'source_path' => $livewire])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->owner->projects()->count());
    }

    public function test_the_draft_keeps_only_areas_that_fit_the_notes()
    {
        $project = $this->importedProject();
        NotesDrafter::fake([[
            'purpose' => 'A place where teams plan their work.',
            'areas' => [
                ['key' => 'Teams', 'name' => 'Teams', 'summary' => 'Make teams and invite people.', 'paths' => ['app/Models/Team.php', 'app/Nowhere/*'], 'behaviors' => [['key' => 'invite', 'name' => 'Invite someone'], ['key' => 'invite', 'name' => 'Invite again']], 'rules' => ['Only owners can delete a team.', ' ']],
                ['key' => 'teams', 'name' => 'Duplicate', 'summary' => '', 'paths' => [], 'behaviors' => [], 'rules' => []],
            ],
        ]]);

        (new DraftProjectNotes($project))->handle($this->repository);

        $project->refresh();
        $this->assertSame(NotesDraftStatus::Ready, $project->notes_draft_status);
        $this->assertSame([
            'purpose' => 'A place where teams plan their work.',
            'areas' => [[
                'key' => 'teams',
                'name' => 'Teams',
                'summary' => 'Make teams and invite people.',
                'paths' => ['app/Models/Team.php'],
                'behaviors' => [['key' => 'invite', 'name' => 'Invite someone']],
                'rules' => ['Only owners can delete a team.'],
            ]],
        ], $project->notes_draft);
        $this->assertCount(1, $project->setup_model_calls ?? []);
        $this->assertSame('planner', $project->setup_model_calls[0]['role'] ?? null);
        NotesDrafter::assertPrompted(fn ($prompt) => $prompt->contains('app/Models/Team.php') && $prompt->contains('--- composer.json'));
    }

    public function test_a_draft_that_cannot_be_made_says_so()
    {
        $project = $this->importedProject();
        NotesDrafter::fake(fn () => throw new RuntimeException('The provider is down.'));

        (new DraftProjectNotes($project))->handle($this->repository);

        $project->refresh();
        $this->assertSame(NotesDraftStatus::Failed, $project->notes_draft_status);
        $this->assertStringStartsWith('I could not read your app', (string) $project->notes_draft_error);
    }

    public function test_keeping_the_draft_writes_notes_the_builder_can_read()
    {
        $project = $this->importedProject(ready: true);

        $this->actingAs($this->owner)
            ->post(route('projects.notes-draft.store', $project))
            ->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertNull($project->notes_draft_status);
        $this->assertNull($project->notes_draft);
        // The notes live with us, so the app's history is untouched.
        $this->assertCount(1, $this->repository->log($project));

        $context = app(ReadProjectContext::class)->current($project);
        $this->assertSame([], $context->problems);
        $this->assertStringContainsString('A place where teams plan their work.', (string) $context->project);
        $this->assertCount(1, $context->capabilities);
        $teams = $context->capabilities['teams'];
        $this->assertSame(['teams', 'Teams', 'Make teams and invite people.'], [$teams->key, $teams->name, $teams->summary]);
        $this->assertSame(['app/Models/Team.php'], $teams->paths);
        $this->assertSame([['key' => 'invite', 'name' => 'Invite someone']], $teams->behaviors);
        $this->assertSame(['Only owners can delete a team.'], $teams->rules());
    }

    public function test_keeping_never_replaces_notes_the_app_already_has()
    {
        $project = $this->importedProject(ready: true);
        app(ProjectNotes::class)->put($project, 'main', ['project.md' => "# Project\n\nWritten by hand.\n"]);

        $this->actingAs($this->owner)
            ->post(route('projects.notes-draft.store', $project))
            ->assertSessionHasErrors(['draft' => 'Your app already has notes, so I did not replace them.']);

        $this->assertSame("# Project\n\nWritten by hand.\n", app(ProjectNotes::class)->files($project)['project.md']);
    }

    public function test_discarding_the_draft_leaves_the_app_unchanged()
    {
        $project = $this->importedProject(ready: true);
        $head = $this->repository->head($project);

        $this->actingAs($this->owner)
            ->delete(route('projects.notes-draft.destroy', $project))
            ->assertRedirect();

        $this->assertNull($project->refresh()->notes_draft_status);
        $this->assertSame($head, $this->repository->head($project));
    }

    public function test_other_people_cannot_keep_or_discard_a_draft()
    {
        $project = $this->importedProject(ready: true);

        $this->actingAs(User::factory()->create())->post(route('projects.notes-draft.store', $project))->assertForbidden();
        $this->delete(route('projects.notes-draft.destroy', $project))->assertForbidden();

        $this->assertSame(NotesDraftStatus::Ready, $project->refresh()->notes_draft_status);
    }

    protected function importedProject(bool $ready = false): Project
    {
        $project = Project::factory()->for($this->owner, 'owner')->create([
            'source_path' => $this->makeProjectSource($this->laravelApp()),
            'notes_draft_status' => $ready ? NotesDraftStatus::Ready : NotesDraftStatus::Drafting,
            'notes_draft' => $ready ? [
                'purpose' => 'A place where teams plan their work.',
                'areas' => [[
                    'key' => 'teams',
                    'name' => 'Teams',
                    'summary' => 'Make teams and invite people.',
                    'paths' => ['app/Models/Team.php'],
                    'behaviors' => [['key' => 'invite', 'name' => 'Invite someone']],
                    'rules' => ['Only owners can delete a team.'],
                ]],
            ] : null,
        ]);
        $this->repository->import($project);

        return $project;
    }
}
