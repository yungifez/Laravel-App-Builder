<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Enums\PreviewStatus;
use App\Jobs\RebuildPreview;
use App\Jobs\StartPreview;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class VisualEditingTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const CARD = <<<'VUE'
    <template>
        <div class="flex gap-4 p-4 text-sm">
            <h1 class="text-xl">Plans</h1>
            <p :class="{ 'font-bold': active }">Pick one</p>
        </div>
    </template>

    VUE;

    protected const PLANS_NOTES = <<<'MARKDOWN'
    ---
    capability: plans
    summary: Customers pick a plan.
    paths: [resources/js/pages/*]
    ---
    # Plans

    ## Rules

    - Every customer sees the same three plans.

    MARKDOWN;

    protected FakeWorkspaceDriver $driver;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            'resources/js/pages/Plans.vue' => self::CARD,
            'resources/js/components/ui/Button.vue' => "<template>\n    <button class=\"px-3\"><slot /></button>\n</template>\n",
            'resources/js/pages/Home.vue' => "<template>\n    <Button>Go</Button>\n</template>\n",
            '.builder/capabilities/plans.md' => self::PLANS_NOTES,
        ]), draftNotes: false);
        $this->repository->import($this->project);

        config([
            'builder.preview.workspace_driver' => 'fake',
            'builder.preview.domain' => 'preview.test',
            'builder.preview.public_port' => null,
            'builder.preview.setup' => [],
            'builder.preview.build' => [['name' => 'Build the frontend', 'command' => ['npm', 'run', 'build'], 'timeout' => 600]],
            'builder.preview.watch.enabled' => false,
            'app.url' => 'http://builder.test',
        ]);
    }

    public function test_the_owner_opens_an_editable_preview_of_the_project_as_it_is_now()
    {
        Http::fake(['*/up' => Http::response('ok')]);

        $this->actingAs($this->owner)
            ->post(route('projects.previews.store', $this->project))
            ->assertRedirect(route('projects.editor.show', $this->project));

        $preview = $this->project->previews()->sole();
        $this->assertSame(PreviewStatus::Ready, $preview->status);
        $this->assertTrue($preview->editable);
        $this->assertNull($preview->feature_request_id);
        $this->assertSame($this->repository->head($this->project), $preview->revision);

        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains(StartPreview::locatorCommand(), $commands);
        $this->assertContains(['npm', 'run', 'build'], $commands);
        $this->assertLessThan(array_search(['npm', 'run', 'build'], $commands, true), array_search(StartPreview::locatorCommand(), $commands, true), 'The locator marks the source before the build.');

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Show')
                ->where('preview.status', 'ready')
                ->where('preview.updating', false)
                ->where('preview.origin', "http://{$preview->host}.preview.test")
                ->missing('element'));
    }

    public function test_inspecting_an_element_says_what_it_is_part_of_and_how_it_looks_per_device()
    {
        $preview = $this->runningPreview();

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => 'resources/js/pages/Plans.vue:2:5']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.tag', 'div')
                ->where('element.editable', true)
                ->where('element.classes', 'flex gap-4 p-4 text-sm')
                ->where('element.values.lg.gap', ['value' => 16, 'from' => 'base'])
                ->where('element.area.name', 'Plans')
                ->where('element.area.rules', ['Every customer sees the same three plans.'])
                ->where('element.revision', $preview->revision)));
    }

    public function test_an_element_whose_look_depends_on_the_app_is_not_editable_in_place()
    {
        $this->runningPreview();

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => 'resources/js/pages/Plans.vue:4:9']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.tag', 'p')
                ->where('element.editable', false)
                ->where('element.reason', 'dynamic')));
    }

    public function test_a_shared_component_reports_how_many_files_use_it()
    {
        $this->runningPreview();

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => 'resources/js/components/ui/Button.vue:2:5']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.shared', ['name' => 'Button', 'uses' => 1])));
    }

    public function test_a_selection_outside_the_project_is_ignored()
    {
        $this->runningPreview();

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => '../../etc/passwd.vue:1:1']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page->where('element', null)));
    }

    public function test_the_owner_changes_how_an_element_looks_as_a_commit_without_a_model()
    {
        Queue::fake();
        $preview = $this->runningPreview();

        $this->actingAs($this->owner)
            ->from(route('projects.editor.show', $this->project))
            ->post(route('visual-edits.store', $this->project), [
                'preview' => $preview->uuid,
                'target' => 'resources/js/pages/Plans.vue:2:5',
                'revision' => $preview->revision,
                'expected' => 'flex gap-4 p-4 text-sm',
                'device' => 'md',
                'changes' => ['gap' => 24, 'padding_x' => 15],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.editor.show', $this->project));

        $contents = $this->repository->show($this->project, $this->repository->head($this->project), 'resources/js/pages/Plans.vue');
        $this->assertStringContainsString('<div class="flex gap-4 p-4 text-sm md:gap-6 md:px-3.75">', (string) $contents);
        $this->assertStringContainsString('<h1 class="text-xl">Plans</h1>', (string) $contents);

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame($this->repository->head($this->project), $edit->commit_sha);
        $this->assertSame($preview->revision, $edit->base_revision);
        $this->assertSame('flex gap-4 p-4 text-sm', $edit->classes_before);
        $this->assertSame('Ada Owner', $this->repository->log($this->project)[0]['author']);
        $this->assertSame('Change how <div> looks on tablets and up', $this->repository->log($this->project)[0]['subject']);

        Queue::assertPushed(RebuildPreview::class, fn (RebuildPreview $job) => $job->preview->is($preview));
    }

    public function test_the_rebuild_after_an_edit_runs_with_the_previews_not_behind_coding_runs()
    {
        config(['builder.preview.queue' => 'previews']);

        $job = new RebuildPreview(Preview::factory()->create());

        $this->assertSame('previews', $job->queue);
        $this->assertGreaterThan(now()->addSeconds($job->timeout), $job->retryUntil());
    }

    public function test_an_edit_made_on_an_old_version_is_refused_and_nothing_is_committed()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $old = (string) $preview->revision;
        // Another commit whose own rebuild is not what this test is about.
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, $old, ['README.md' => "Hi\n"], 'Another change', null));
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.store', $this->project), [
                'preview' => $preview->uuid,
                'target' => 'resources/js/pages/Plans.vue:2:5',
                'revision' => $old,
                'expected' => 'flex gap-4 p-4 text-sm',
                'device' => 'base',
                'changes' => ['gap' => 24],
            ])
            ->assertSessionHasErrors('edit');

        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertSame(0, $this->project->visualEdits()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_owner_undoes_an_edit_and_the_preview_is_rebuilt()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $before = $this->repository->show($this->project, $preview->revision, 'resources/js/pages/Plans.vue');

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
            'expected' => 'flex gap-4 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertSessionHasNoErrors();
        $edit = $this->project->visualEdits()->sole();

        $this->actingAs($this->owner)
            ->post(route('visual-edits.reversion.store', $edit))
            ->assertSessionHasNoErrors();

        $edit->refresh();
        $this->assertNotNull($edit->reverted_at);
        $this->assertSame($this->repository->head($this->project), $edit->revert_sha);
        $this->assertSame($before, $this->repository->show($this->project, $this->repository->head($this->project), 'resources/js/pages/Plans.vue'));
        $this->assertSame('Undo a change to how <div> looks', $this->repository->log($this->project, 1)[0]['subject']);
        Queue::assertPushed(RebuildPreview::class, 2);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.reversion.store', $edit))
            ->assertSessionHasErrors(['edit' => 'This change was already undone.']);

        $this->actingAs(User::factory()->create())
            ->post(route('visual-edits.reversion.store', $edit))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('edits.0.properties', ['gap'])
                ->whereNot('edits.0.reverted_at', null));
    }

    public function test_an_edit_is_refused_when_the_element_no_longer_has_the_classes_the_owner_saw()
    {
        Queue::fake();
        $preview = $this->runningPreview();

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
            'expected' => 'flex gap-2 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertSessionHasErrors(['edit' => 'This part was changed since you picked it. Pick it again to see how it looks now.']);

        $this->assertSame($preview->revision, $this->repository->head($this->project));
        $this->assertSame(0, $this->project->visualEdits()->count());
    }

    public function test_the_next_automatic_save_builds_on_the_last_one_without_waiting_for_the_rebuild()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $save = fn (string $revision, string $expected, array $changes) => $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $revision,
            'expected' => $expected,
            'device' => 'base',
            'changes' => $changes,
        ]);

        $save((string) $preview->revision, 'flex gap-4 p-4 text-sm', ['gap' => 24])->assertSessionHasNoErrors();

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('edits.0.classes', 'flex gap-6 p-4 text-sm')
                ->where('edits.0.revision', $this->repository->head($this->project)));

        $save($this->repository->head($this->project), 'flex gap-6 p-4 text-sm', ['padding_x' => 8])->assertSessionHasNoErrors();

        $this->assertStringContainsString('<div class="flex gap-6 px-2 py-4 text-sm">', (string) $this->repository->show($this->project, $this->repository->head($this->project), 'resources/js/pages/Plans.vue'));
        $this->assertSame(2, $this->project->visualEdits()->count());
    }

    public function test_the_owner_redoes_an_undone_edit()
    {
        Queue::fake();
        $preview = $this->runningPreview();

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
            'expected' => 'flex gap-4 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertSessionHasNoErrors();
        $edit = $this->project->visualEdits()->sole();

        $this->actingAs($this->owner)->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();

        $edit->refresh();
        $this->assertNull($edit->reverted_at);
        $this->assertSame($this->repository->head($this->project), $edit->commit_sha);
        $this->assertStringContainsString('<div class="flex gap-6 p-4 text-sm">', (string) $this->repository->show($this->project, $edit->commit_sha, 'resources/js/pages/Plans.vue'));
        $this->assertSame('Redo a change to how <div> looks', $this->repository->log($this->project, 1)[0]['subject']);

        $this->actingAs($this->owner)
            ->delete(route('visual-edits.reversion.destroy', $edit))
            ->assertSessionHasErrors(['edit' => 'This change is already in place.']);

        $this->actingAs(User::factory()->create())
            ->delete(route('visual-edits.reversion.destroy', $edit))
            ->assertForbidden();
    }

    public function test_undo_is_refused_when_something_else_changed_the_element_since()
    {
        Queue::fake();
        $preview = $this->runningPreview();

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
            'expected' => 'flex gap-4 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertSessionHasNoErrors();
        $edit = $this->project->visualEdits()->sole();

        // A model changes the same element in a later commit.
        $head = $this->repository->head($this->project);
        $contents = (string) $this->repository->show($this->project, $head, 'resources/js/pages/Plans.vue');
        $this->repository->commitFiles($this->project, $head, ['resources/js/pages/Plans.vue' => str_replace('flex gap-6 p-4 text-sm', 'flex gap-6 p-4 text-sm shadow', $contents)], 'Add a shadow', null);
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.reversion.store', $edit))
            ->assertSessionHasErrors(['edit' => 'This part was changed since, so going back would lose that change.']);

        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertNull($edit->fresh()->reverted_at);
    }

    public function test_edits_that_cannot_be_made_in_place_are_refused()
    {
        $preview = $this->runningPreview();
        $edit = fn (array $data) => $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), $data + [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
            'expected' => 'flex gap-4 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ]);

        $edit(['target' => 'resources/js/pages/Plans.vue:4:9'])->assertSessionHasErrors('edit');
        $edit(['changes' => ['radius' => 'wobbly']])->assertSessionHasErrors('edit');
        $edit(['changes' => ['colour' => 'red']])->assertSessionHasErrors('changes');
        $edit(['device' => 'xl'])->assertSessionHasErrors('device');
        $edit(['target' => '/etc/passwd.vue:1:1'])->assertSessionHasErrors('target');
        $edit(['changes' => ['gap' => 16]])->assertSessionHasErrors('edit');

        $this->assertSame(0, $this->project->visualEdits()->count());
    }

    public function test_other_users_cannot_open_inspect_or_edit_a_project()
    {
        $preview = $this->runningPreview();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('projects.show', $this->project))->assertForbidden();
        $this->actingAs($stranger)->post(route('projects.previews.store', $this->project))->assertForbidden();
        $this->actingAs($stranger)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
            'expected' => 'flex gap-4 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertForbidden();
    }

    public function test_an_edit_cannot_use_another_projects_preview()
    {
        $this->runningPreview();
        $other = Preview::factory()->editable('abc')->ready()->create();

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $other->uuid,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $this->repository->head($this->project),
            'expected' => 'flex gap-4 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertSessionHasErrors('preview');
    }

    public function test_the_preview_is_rebuilt_with_the_changed_files_and_shows_them_as_updating_until_then()
    {
        $preview = $this->runningPreview();
        $old = (string) $preview->revision;
        $this->repository->commitFiles($this->project, $old, ['resources/js/pages/Plans.vue' => "<template><div class=\"gap-8\" /></template>\n"], 'Edit', null);
        $this->repository->git($this->project, ['rm', '-q', 'resources/js/pages/Home.vue']);
        $this->repository->git($this->project, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', 'Remove home']);
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('preview.updating', true));

        RebuildPreview::dispatchSync($preview);

        $workspace = (string) $preview->workspace->driver_id;
        $this->assertSame("<template><div class=\"gap-8\" /></template>\n", $this->driver->files["{$workspace}:resources/js/pages/Plans.vue"]);
        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains(['rm', '-f', '--', 'resources/js/pages/Home.vue'], $commands);
        $this->assertContains(StartPreview::locatorCommand(), $commands);
        $this->assertContains(['npm', 'run', 'build'], $commands);

        $preview->refresh();
        $this->assertSame($head, $preview->revision);
        $this->assertNotNull($preview->rebuilt_at);
        $this->assertDatabaseHas('preview_rebuilds', ['preview_id' => $preview->id, 'to_revision' => $head, 'status' => 'rebuilt']);

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('preview.updating', false));
    }

    public function test_an_editable_preview_keeps_its_build_watching_in_place_of_the_build_steps()
    {
        Http::fake(['*/up' => Http::response('ok')]);
        config(['builder.preview.watch.enabled' => true]);

        $this->actingAs($this->owner)->post(route('projects.previews.store', $this->project));

        $preview = $this->project->previews()->sole();
        $this->assertSame(PreviewStatus::Ready, $preview->status);
        $this->assertTrue($preview->watching);

        $watcher = $this->driver->services[0];
        $this->assertSame(0, $watcher['port']);
        $this->assertSame(StartPreview::watchCommand(null, 'watch', ['build started', 'built in', 'npm', 'run', 'build', '--', '--watch']), $watcher['command']);

        $commands = array_column($this->driver->executed, 'command');
        $this->assertContains(StartPreview::watchCommand(null, 'wait', ['150', '60']), $commands);
        $this->assertNotContains(['npm', 'run', 'build'], $commands);
    }

    public function test_a_watcher_that_does_not_build_in_time_leaves_the_build_to_the_build_steps()
    {
        Http::fake(['*/up' => Http::response('ok')]);
        config(['builder.preview.watch.enabled' => true]);
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: in_array('wait', $command, true) ? 4 : 0, output: '', errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)->post(route('projects.previews.store', $this->project));

        $preview = $this->project->previews()->sole();
        $this->assertSame(PreviewStatus::Ready, $preview->status);
        $this->assertFalse($preview->watching);
        $this->assertContains(['npm', 'run', 'build'], array_column($this->driver->executed, 'command'));
    }

    public function test_a_watching_preview_is_rebuilt_by_moving_the_marked_files_in_at_once()
    {
        $preview = $this->runningPreview(['watching' => true]);
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, (string) $preview->revision, ['resources/js/pages/Plans.vue' => "<template><div class=\"gap-8\" /></template>\n"], 'Edit', null));
        $this->repository->git($this->project, ['rm', '-q', 'resources/js/pages/Home.vue']);
        $this->repository->git($this->project, ['-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-q', '-m', 'Remove home']);
        $head = $this->repository->head($this->project);

        RebuildPreview::dispatchSync($preview);

        $workspace = (string) $preview->workspace->driver_id;
        $this->assertSame("<template><div class=\"gap-8\" /></template>\n", $this->driver->files["{$workspace}:node_modules/.cache/preview-watch/stage/resources/js/pages/Plans.vue"]);
        $this->assertArrayNotHasKey("{$workspace}:resources/js/pages/Plans.vue", $this->driver->files, 'The watching build sees the file only once it is marked.');

        $commands = array_column($this->driver->executed, 'command');
        $this->assertSame([
            StartPreview::locatorCommand(null, 'node_modules/.cache/preview-watch/stage'),
            StartPreview::watchCommand(null, 'place', ['150', '60', 'resources/js/pages/Home.vue']),
        ], $commands);

        $preview->refresh();
        $this->assertSame($head, $preview->revision);
        $this->assertTrue($preview->watching);
        $this->assertDatabaseHas('preview_rebuilds', ['preview_id' => $preview->id, 'to_revision' => $head, 'status' => 'rebuilt']);
    }

    public function test_a_preview_whose_watcher_stopped_is_rebuilt_as_usual_from_then_on()
    {
        $preview = $this->runningPreview(['watching' => true]);
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, (string) $preview->revision, ['resources/js/pages/Plans.vue' => "<template />\n"], 'Edit', null));
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: in_array('place', $command, true) ? 3 : 0, output: '', errorOutput: '', durationMs: 5);

        RebuildPreview::dispatchSync($preview);

        $workspace = (string) $preview->workspace->driver_id;
        $this->assertSame("<template />\n", $this->driver->files["{$workspace}:resources/js/pages/Plans.vue"]);
        $this->assertContains(['npm', 'run', 'build'], array_column($this->driver->executed, 'command'));

        $preview->refresh();
        $this->assertFalse($preview->watching);
        $this->assertSame($this->repository->head($this->project), $preview->revision);
    }

    public function test_any_new_commit_brings_the_editable_preview_up_to_date()
    {
        Queue::fake();
        $preview = $this->runningPreview();

        $this->repository->commitFiles($this->project, (string) $preview->revision, ['.builder/project.md' => "# Acme\n"], 'Describe what the app is for', null);

        Queue::assertPushed(RebuildPreview::class, fn (RebuildPreview $job) => $job->preview->is($preview));

        Queue::fake();
        $preview->update(['status' => PreviewStatus::Stopped]);
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), ['README.md' => "Hi\n"], 'Another change', null);

        Queue::assertNothingPushed();
    }

    public function test_a_failed_rebuild_is_reported_and_keeps_the_old_revision_and_files()
    {
        $preview = $this->runningPreview();
        $old = (string) $preview->revision;
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, $old, ['resources/js/pages/Plans.vue' => "<template />\n"], 'Edit', null));
        $this->driver->onExec = fn () => new CommandResult(exitCode: 1, output: '', errorOutput: 'Build failed', durationMs: 5);

        RebuildPreview::dispatchSync($preview);

        $preview->refresh();
        $this->assertSame($old, $preview->revision);
        $this->assertSame('The preview could not show your latest change. Start it again to see it.', $preview->error);
        $this->assertDatabaseHas('preview_rebuilds', ['preview_id' => $preview->id, 'from_revision' => $old, 'status' => 'failed']);

        // An undo back to the old file changes nothing to copy, so the
        // failed file must not be left in the workspace.
        $workspace = (string) $preview->workspace->driver_id;
        $this->assertSame($this->repository->show($this->project, $old, 'resources/js/pages/Plans.vue'), $this->driver->files["{$workspace}:resources/js/pages/Plans.vue"]);
    }

    public function test_a_preview_whose_rebuild_failed_asks_to_be_started_again()
    {
        $preview = $this->runningPreview();
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, (string) $preview->revision, ['resources/js/pages/Plans.vue' => "<template>\n    <div class=\"gap-8\" />\n</template>\n"], 'Edit', null));
        $preview->update(['error' => 'The preview could not show your latest change. Start it again to see it.']);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => 'resources/js/pages/Plans.vue:2:5']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.updating', false)
                ->where('preview.error', 'The preview could not show your latest change. Start it again to see it.')
                ->reloadOnly('element', fn (Assert $page) => $page
                    ->where('element.editable', false)
                    ->where('element.reason', 'behind')));
    }

    public function test_the_gateway_adds_the_overlay_to_editable_pages_and_lets_only_the_builder_embed_them()
    {
        $preview = $this->runningPreview(['session_hash' => hash('sha256', 'secret'), 'session_expires_at' => now()->addHour()]);
        Http::fake(['http://127.0.0.1:20001/*' => Http::response('<html><body><h1>Plans</h1></body></html>', 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => '44',
            'X-Frame-Options' => 'SAMEORIGIN',
        ])]);

        $response = $this->call('GET', "http://{$preview->host}.preview.test/", [], ['builder_preview' => 'secret'], [], ['HTTP_COOKIE' => 'builder_preview=secret']);

        $response->assertOk();
        $body = (string) $response->getContent();
        $this->assertStringContainsString('<h1>Plans</h1><script data-builder-origin="http://builder.test">', $body);
        $this->assertStringEndsWith('</script></body></html>', $body);
        $this->assertNull($response->headers->get('X-Frame-Options'));
        $this->assertNull($response->headers->get('Content-Length'));
        $this->assertSame('frame-ancestors http://builder.test', $response->headers->get('Content-Security-Policy'));
    }

    public function test_the_gateway_leaves_other_responses_and_ordinary_previews_alone()
    {
        $editable = $this->runningPreview(['session_hash' => hash('sha256', 'secret'), 'session_expires_at' => now()->addHour()]);
        $ordinary = Preview::factory()->ready()->create(['session_hash' => hash('sha256', 'other'), 'session_expires_at' => now()->addHour()]);
        Http::fake(['http://127.0.0.1:20001/app.js' => Http::response('console.log("</body>")', 200, ['Content-Type' => 'text/javascript']), '*' => Http::response('<body></body>', 200, ['Content-Type' => 'text/html'])]);

        $script = $this->call('GET', "http://{$editable->host}.preview.test/app.js", [], ['builder_preview' => 'secret'], [], ['HTTP_COOKIE' => 'builder_preview=secret']);
        $this->assertSame('console.log("</body>")', $script->getContent());

        $page = $this->call('GET', "http://{$ordinary->host}.preview.test/", [], ['builder_preview' => 'other'], [], ['HTTP_COOKIE' => 'builder_preview=other']);
        // An ordinary preview gets no overlay, but the builder may still frame
        // it so the owner can use a change's copy.
        $this->assertSame('<body></body>', $page->getContent());
        $this->assertSame('frame-ancestors http://builder.test', $page->headers->get('Content-Security-Policy'));
    }

    public function test_an_editable_previews_session_cookie_works_inside_the_builder()
    {
        $preview = $this->runningPreview();

        $location = $this->actingAs($this->owner)->get(route('previews.show', $preview))->headers->get('Location');
        $cookie = collect($this->get((string) $location)->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'builder_preview');

        $this->assertSame('none', $cookie->getSameSite());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isPartitioned());
    }

    /**
     * Create a running editable preview of the project at its latest commit.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function test_the_owner_drags_a_part_after_its_sibling_and_can_undo_and_redo_it()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $file = 'resources/js/pages/Plans.vue';
        $moved = "<template>\n    <div class=\"flex gap-4 p-4 text-sm\">\n        <p :class=\"{ 'font-bold': active }\">Pick one</p>\n        <h1 class=\"text-xl\">Plans</h1>\n    </div>\n</template>\n";

        $this->actingAs($this->owner)->post(route('visual-moves.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'to' => "{$file}:4:9",
            'placement' => 'after',
            'revision' => $preview->revision,
        ])->assertSessionHasNoErrors();

        $head = $this->repository->head($this->project);
        $this->assertSame($moved, $this->repository->show($this->project, $head, $file));

        $edit = $this->project->visualEdits()->sole();
        $this->assertTrue($edit->moves());
        $this->assertSame([4, 9], [$edit->line, $edit->column]);

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->hasFlash('moved', ['target' => "{$file}:4:9", 'instance' => false])
                ->where('edits.0.kind', 'move')
                ->where('edits.0.properties', []));

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::CARD, $this->repository->show($this->project, $this->repository->head($this->project), $file));

        $this->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();
        $this->assertSame($moved, $this->repository->show($this->project, $this->repository->head($this->project), $file));
        $this->assertNull($edit->fresh()->reverted_at);
    }

    public function test_the_owner_types_new_words_over_a_part_and_can_undo_and_redo_them()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $file = 'resources/js/pages/Plans.vue';
        $reworded = str_replace('>Plans</h1>', '>Plans &amp; prices</h1>', self::CARD);

        $this->actingAs($this->owner)->post(route('visual-texts.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'before' => 'Plans',
            'text' => ' Plans & prices ',
            'revision' => $preview->revision,
        ])->assertSessionHasNoErrors();

        $this->assertSame($reworded, $this->repository->show($this->project, $this->repository->head($this->project), $file));

        $edit = $this->project->visualEdits()->sole();
        $this->assertTrue($edit->rewords());

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('edits.0.kind', 'text')
                ->where('edits.0.words', 'Plans & prices')
                ->where('edits.0.words_before', 'Plans')
                ->where('edits.0.properties', [])
                ->where('edits.0.sides', null));

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::CARD, $this->repository->show($this->project, $this->repository->head($this->project), $file));

        $this->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();
        $this->assertSame($reworded, $this->repository->show($this->project, $this->repository->head($this->project), $file));
    }

    public function test_words_that_come_from_the_app_or_changed_since_are_not_changed_in_place()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $reword = fn (array $data) => $this->actingAs($this->owner)->post(route('visual-texts.store', $this->project), $data + [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:3:9',
            'before' => 'Plans',
            'text' => 'Prices',
            'revision' => $preview->revision,
        ]);

        // A slot is filled where the part is used, not where it is defined.
        $reword(['target' => 'resources/js/components/ui/Button.vue:2:5', 'before' => 'Go'])
            ->assertSessionHasErrors(['edit' => 'These words come from your app\'s data or code, so I can\'t change them here. Ask me to change them instead.']);
        $reword(['target' => 'resources/js/pages/Plans.vue:2:5'])->assertSessionHasErrors('edit');
        $reword(['before' => 'Pricing'])->assertSessionHasErrors(['edit' => 'These words were changed since. Look again and try once more.']);
        $reword(['text' => 'Hi {{ name }}'])->assertSessionHasErrors('text');
        $this->assertSame($preview->revision, $this->repository->head($this->project));

        // Where the shared button is used, its words are plain.
        $reword(['target' => 'resources/js/pages/Home.vue:2:5', 'instance' => true, 'before' => 'Go', 'text' => 'Start'])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('<Button>Start</Button>', (string) $this->repository->show($this->project, $this->repository->head($this->project), 'resources/js/pages/Home.vue'));
    }

    protected const NAV = <<<'VUE'
    <template>
        <nav>
            <a href="/plans" class="underline">Plans</a>
            <a :href="route('home')">Home</a>
        </nav>
    </template>

    VUE;

    public function test_the_owner_changes_where_a_link_goes_and_can_undo_and_redo_it()
    {
        Queue::fake();
        $file = 'resources/js/pages/Nav.vue';
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$file => self::NAV], 'Add links', ['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $preview = $this->runningPreview();
        $relinked = str_replace('href="/plans"', 'href="https://example.com/?a=1&amp;b=2"', self::NAV);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => "{$file}:3:9"]))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.link', ['href' => '/plans'])));

        $this->post(route('visual-links.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'before' => '/plans',
            'href' => ' https://example.com/?a=1&b=2 ',
            'revision' => $preview->revision,
        ])->assertSessionHasNoErrors();

        $this->assertSame($relinked, $this->repository->show($this->project, $this->repository->head($this->project), $file));

        $edit = $this->project->visualEdits()->sole();
        $this->assertTrue($edit->relinks());

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('edits.0.kind', 'link')
                ->where('edits.0.link', 'https://example.com/?a=1&b=2')
                ->where('edits.0.sides', null));

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::NAV, $this->repository->show($this->project, $this->repository->head($this->project), $file));

        $this->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();
        $this->assertSame($relinked, $this->repository->show($this->project, $this->repository->head($this->project), $file));
    }

    protected const THEME = <<<'CSS'
    @custom-variant dark (&:is(.dark *));

    :root {
        --background: hsl(0 0% 100%);
        --primary: hsl(0 0% 9%);
    }

    .dark {
        --background: hsl(0 0% 3.9%);
        --primary: hsl(0 0% 98%);
    }

    CSS;

    public function test_the_owner_changes_a_theme_colour_of_one_look_and_can_undo_and_redo_it()
    {
        Queue::fake();
        $file = 'resources/styles/brand.css';
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$file => self::THEME], 'Add a theme', ['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $preview = $this->runningPreview();
        $changed = str_replace('--primary: hsl(0 0% 98%);', '--primary: #2563eb;', self::THEME);

        $this->actingAs($this->owner)->post(route('theme-colors.store', $this->project), [
            'preview' => $preview->uuid,
            'mode' => 'dark',
            'token' => 'primary',
            'color' => '#2563EB',
            'revision' => $preview->revision,
        ])->assertSessionHasNoErrors();

        // Only the dark look's colour changes, wherever the stylesheet is.
        $this->assertSame($changed, $this->repository->show($this->project, $this->repository->head($this->project), $file));

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame('theme', $edit->kind());
        $this->assertSame(['mode' => 'dark', 'token' => 'primary', 'before' => 'hsl(0 0% 98%)', 'after' => '#2563eb'], $edit->changes['theme']);

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('edits.0.kind', 'theme')
                ->where('edits.0.theme', ['mode' => 'dark', 'token' => 'primary', 'before' => 'hsl(0 0% 98%)', 'after' => '#2563eb']));

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::THEME, $this->repository->show($this->project, $this->repository->head($this->project), $file));

        $this->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();
        $this->assertSame($changed, $this->repository->show($this->project, $this->repository->head($this->project), $file));
    }

    public function test_the_owner_changes_a_colour_of_an_app_that_does_not_use_shadcn()
    {
        Queue::fake();
        $file = 'resources/css/tokens.css';
        $css = "@theme {\n    --color-brand-500: oklch(0.55 0.2 290);\n    --spacing-gutter: 24px;\n}\n";
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$file => $css], 'Add tokens', ['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $preview = $this->runningPreview();

        // The panel offers the colour by the name its classes use.
        $this->actingAs($this->owner)->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('colors')->loadDeferredProps(fn (Assert $page) => $page
                ->where('colors', [['name' => 'brand-500', 'variable' => 'color-brand-500', 'classes' => true]])));

        $this->post(route('theme-colors.store', $this->project), [
            'preview' => $preview->uuid,
            'mode' => 'light',
            'token' => 'color-brand-500',
            'color' => '#7c3aed',
            'revision' => $preview->revision,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            str_replace('oklch(0.55 0.2 290)', '#7c3aed', $css),
            $this->repository->show($this->project, $this->repository->head($this->project), $file),
        );
    }

    public function test_a_theme_colour_the_app_does_not_write_or_a_value_that_is_not_a_colour_is_refused()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $recolor = fn (array $data) => $this->actingAs($this->owner)->post(route('theme-colors.store', $this->project), $data + [
            'preview' => $preview->uuid,
            'mode' => 'light',
            'token' => 'primary',
            'color' => '#2563eb',
            'revision' => $preview->revision,
        ]);

        $recolor(['color' => 'red; } body { display: none'])->assertSessionHasErrors('color');
        $recolor(['token' => 'primary: red; --x'])->assertSessionHasErrors('token');
        $recolor(['mode' => 'sepia'])->assertSessionHasErrors('mode');
        $recolor([])->assertSessionHasErrors(['edit' => 'Your app keeps this colour some other way, so I can\'t change it here. Ask me to change it instead.']);
        $this->assertSame($preview->revision, $this->repository->head($this->project));
        $this->assertSame(0, $this->project->visualEdits()->count());
    }

    public function test_a_link_the_app_decides_or_an_unsafe_address_is_not_changed_in_place()
    {
        Queue::fake();
        $file = 'resources/js/pages/Nav.vue';
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$file => self::NAV], 'Add links', ['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $preview = $this->runningPreview();
        $relink = fn (array $data) => $this->actingAs($this->owner)->post(route('visual-links.store', $this->project), $data + [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'before' => '/plans',
            'href' => '/prices',
            'revision' => $preview->revision,
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => "{$file}:4:9"]))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.link', ['href' => null])));

        $relink(['target' => "{$file}:4:9", 'before' => '/'])
            ->assertSessionHasErrors(['edit' => 'Your app decides where this link goes, so I can\'t change it here. Ask me to change it instead.']);
        $relink(['before' => '/pricing'])->assertSessionHasErrors(['edit' => 'This link was changed since. Look again and try once more.']);

        foreach (['javascript:alert(1)', '//evil.test', 'plans', '/a" onclick="x', '/{{ page }}'] as $address) {
            $relink(['href' => $address])->assertSessionHasErrors('href');
        }

        $this->assertSame($preview->revision, $this->repository->head($this->project));
    }

    public function test_moves_between_files_or_across_parents_are_refused_and_undo_keeps_later_changes()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $move = fn (array $data) => $this->actingAs($this->owner)->post(route('visual-moves.store', $this->project), $data + [
            'preview' => $preview->uuid,
            'target' => 'resources/js/pages/Plans.vue:3:9',
            'to' => 'resources/js/pages/Plans.vue:4:9',
            'placement' => 'before',
            'revision' => $preview->revision,
        ]);

        $move(['to' => 'resources/js/pages/Home.vue:2:5'])->assertSessionHasErrors('edit');
        $move(['to' => 'resources/js/pages/Plans.vue:2:5'])->assertSessionHasErrors(['edit' => 'This part cannot be moved there. Ask me to move it instead.']);
        $move(['placement' => 'inside'])->assertSessionHasErrors('placement');
        $this->assertSame($preview->revision, $this->repository->head($this->project));

        $move(['placement' => 'after'])->assertSessionHasNoErrors();
        $edit = $this->project->visualEdits()->sole();

        // Something else changes the same page after the move.
        $head = $this->repository->head($this->project);
        $contents = (string) $this->repository->show($this->project, $head, 'resources/js/pages/Plans.vue');
        $this->repository->commitFiles($this->project, $head, ['resources/js/pages/Plans.vue' => str_replace('Pick one', 'Choose one', $contents)], 'Reword', null);
        $head = $this->repository->head($this->project);

        $this->post(route('visual-edits.reversion.store', $edit))
            ->assertSessionHasErrors(['edit' => 'This page was changed since, so going back would lose that change.']);
        $this->assertSame($head, $this->repository->head($this->project));
    }

    public function test_other_parts_stay_editable_while_the_preview_rebuilds_after_a_save()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $file = 'resources/js/pages/Plans.vue';

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:2:5",
            'revision' => $preview->revision,
            'expected' => 'flex gap-4 p-4 text-sm',
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertSessionHasNoErrors();

        $this->get(route('projects.show', [$this->project, 'design' => 1, 'target' => "{$file}:3:9"]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.updating', true)
                ->where('edits.0.target', "{$file}:2:5")
                ->where('edits.0.sides.before.classes', 'flex gap-4 p-4 text-sm')
                ->where('edits.0.sides.before.values.gap', 16)
                ->where('edits.0.sides.after.values.gap', 24)
                ->reloadOnly('element', fn (Assert $page) => $page
                    ->where('element.reason', null)
                    ->where('element.editable', true)
                    ->where('element.classes', 'text-xl')));

        $this->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'revision' => $this->repository->head($this->project),
            'expected' => 'text-xl',
            'device' => 'base',
            'changes' => ['gap' => 8],
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString('<h1 class="text-xl gap-2">Plans</h1>', (string) $this->repository->show($this->project, $this->repository->head($this->project), $file));
    }

    public function test_an_edit_follows_a_part_whose_lines_moved_since_the_preview_was_built()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $file = 'resources/js/pages/Plans.vue';
        // A new line above the heading: the running preview still says it
        // is on line 3.
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, (string) $preview->revision, [
            $file => str_replace('        <h1', "        <span>New</span>\n        <h1", self::CARD),
        ], 'Add a line', null));

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'revision' => $this->repository->head($this->project),
            'expected' => 'text-xl',
            'device' => 'base',
            'changes' => ['gap' => 8],
        ])->assertSessionHasNoErrors();

        $contents = (string) $this->repository->show($this->project, $this->repository->head($this->project), $file);
        $this->assertStringContainsString("<span>New</span>\n        <h1 class=\"text-xl gap-2\">Plans</h1>", $contents);
        $this->assertSame(4, $this->project->visualEdits()->sole()->line);
    }

    public function test_a_part_moved_twice_before_the_rebuild_is_followed_both_times()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $file = 'resources/js/pages/Plans.vue';
        $move = fn (string $placement) => $this->actingAs($this->owner)->post(route('visual-moves.store', $this->project), [
            'preview' => $preview->uuid,
            // Both places are where the running preview still shows them.
            'target' => "{$file}:3:9",
            'to' => "{$file}:4:9",
            'placement' => $placement,
            'revision' => $this->repository->head($this->project),
        ]);

        $move('after')->assertSessionHasNoErrors();
        $move('before')->assertSessionHasNoErrors();

        $this->assertSame(self::CARD, $this->repository->show($this->project, $this->repository->head($this->project), $file));
        $this->assertSame(2, $this->project->visualEdits()->count());
    }

    public function test_a_part_that_looks_like_its_sibling_is_not_mistaken_for_it_after_a_move()
    {
        Queue::fake();
        $file = 'resources/js/pages/Steps.vue';
        $steps = <<<'VUE'
        <template>
            <ul>
                <li
                    class="py-2 before:top-1/2"
                >
                    <span>One</span>
                </li>
                <li
                    class="py-2 before:top-0"
                >
                    <span>Two</span>
                </li>
            </ul>
        </template>

        VUE;
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, $this->repository->head($this->project), [$file => $steps], 'Add steps', null));
        $preview = $this->runningPreview();
        // A diff lines up the two items' matching lines, so following the
        // first item's lines would find the second one.
        $move = fn (string $placement) => $this->actingAs($this->owner)->post(route('visual-moves.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'to' => "{$file}:8:9",
            'placement' => $placement,
            'revision' => $this->repository->head($this->project),
        ]);

        $move('after')->assertSessionHasNoErrors();
        $move('before')->assertSessionHasNoErrors();

        $this->assertSame($steps, $this->repository->show($this->project, $this->repository->head($this->project), $file));
    }

    public function test_a_new_look_after_a_move_changes_the_moved_part_not_the_one_now_in_its_place()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $file = 'resources/js/pages/Plans.vue';

        $this->actingAs($this->owner)->post(route('visual-moves.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'to' => "{$file}:4:9",
            'placement' => 'after',
            'revision' => $preview->revision,
        ])->assertSessionHasNoErrors();

        $this->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'revision' => $this->repository->head($this->project),
            'expected' => 'text-xl',
            'device' => 'base',
            'changes' => ['gap' => 8],
        ])->assertSessionHasNoErrors();

        $contents = (string) $this->repository->show($this->project, $this->repository->head($this->project), $file);
        $this->assertStringContainsString("<p :class=\"{ 'font-bold': active }\">Pick one</p>\n        <h1 class=\"text-xl gap-2\">Plans</h1>", $contents);
    }

    public function test_a_part_whose_own_lines_were_rewritten_waits_for_the_rebuild()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $file = 'resources/js/pages/Plans.vue';
        Event::fakeFor(fn () => $this->repository->commitFiles($this->project, (string) $preview->revision, [
            $file => str_replace('<h1 class="text-xl">Plans</h1>', "<h1 class=\"text-xl\">\n            Plans\n        </h1>", self::CARD),
        ], 'Reflow', null));

        $this->actingAs($this->owner)
            ->get(route('projects.show', [$this->project, 'design' => 1, 'target' => "{$file}:3:9"]))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page->where('element.reason', 'updating')));

        $this->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->uuid,
            'target' => "{$file}:3:9",
            'revision' => $this->repository->head($this->project),
            'expected' => 'text-xl',
            'device' => 'base',
            'changes' => ['gap' => 8],
        ])->assertSessionHasErrors(['edit' => 'Your last change is still going in. Try again in a moment.']);
    }

    protected function runningPreview(array $attributes = []): Preview
    {
        return Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
            ...$attributes,
        ]);
    }

    public function test_the_old_editor_address_opens_the_workspace_in_design_mode()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.editor.show', $this->project))
            ->assertRedirect(route('projects.show', ['project' => $this->project, 'design' => 1]));
    }
}
