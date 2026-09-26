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
        ]));
        $this->repository->import($this->project);

        config([
            'builder.preview.workspace_driver' => 'fake',
            'builder.preview.domain' => 'preview.test',
            'builder.preview.public_port' => null,
            'builder.preview.setup' => [],
            'builder.preview.rebuild' => [['name' => 'Build the frontend', 'command' => ['npm', 'run', 'build'], 'timeout' => 600]],
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

        $this->get(route('projects.editor.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/Editor')
                ->where('preview.status', 'ready')
                ->where('preview.updating', false)
                ->where('preview.origin', "http://{$preview->host}.preview.test")
                ->missing('element'));
    }

    public function test_inspecting_an_element_says_what_it_is_part_of_and_how_it_looks_per_device()
    {
        $preview = $this->runningPreview();

        $this->actingAs($this->owner)
            ->get(route('projects.editor.show', ['project' => $this->project, 'target' => 'resources/js/pages/Plans.vue:2:5']))
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
            ->get(route('projects.editor.show', ['project' => $this->project, 'target' => 'resources/js/pages/Plans.vue:4:9']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.tag', 'p')
                ->where('element.editable', false)
                ->where('element.reason', 'dynamic')));
    }

    public function test_a_shared_component_reports_how_many_files_use_it()
    {
        $this->runningPreview();

        $this->actingAs($this->owner)
            ->get(route('projects.editor.show', ['project' => $this->project, 'target' => 'resources/js/components/ui/Button.vue:2:5']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.shared', ['name' => 'Button', 'uses' => 1])));
    }

    public function test_a_selection_outside_the_project_is_ignored()
    {
        $this->runningPreview();

        $this->actingAs($this->owner)
            ->get(route('projects.editor.show', ['project' => $this->project, 'target' => '../../etc/passwd.vue:1:1']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page->where('element', null)));
    }

    public function test_the_owner_changes_how_an_element_looks_as_a_commit_without_a_model()
    {
        Queue::fake();
        $preview = $this->runningPreview();

        $this->actingAs($this->owner)
            ->from(route('projects.editor.show', $this->project))
            ->post(route('visual-edits.store', $this->project), [
                'preview' => $preview->id,
                'target' => 'resources/js/pages/Plans.vue:2:5',
                'revision' => $preview->revision,
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

    public function test_an_edit_made_on_an_old_version_is_refused_and_nothing_is_committed()
    {
        Queue::fake();
        $preview = $this->runningPreview();
        $old = (string) $preview->revision;
        $this->repository->commitFiles($this->project, $old, ['README.md' => "Hi\n"], 'Another change', null);
        $head = $this->repository->head($this->project);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.store', $this->project), [
                'preview' => $preview->id,
                'target' => 'resources/js/pages/Plans.vue:2:5',
                'revision' => $old,
                'device' => 'base',
                'changes' => ['gap' => 24],
            ])
            ->assertSessionHasErrors('edit');

        $this->assertSame($head, $this->repository->head($this->project));
        $this->assertSame(0, $this->project->visualEdits()->count());
        Queue::assertNothingPushed();
    }

    public function test_edits_that_cannot_be_made_in_place_are_refused()
    {
        $preview = $this->runningPreview();
        $edit = fn (array $data) => $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), $data + [
            'preview' => $preview->id,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
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

        $this->actingAs($stranger)->get(route('projects.editor.show', $this->project))->assertForbidden();
        $this->actingAs($stranger)->post(route('projects.previews.store', $this->project))->assertForbidden();
        $this->actingAs($stranger)->post(route('visual-edits.store', $this->project), [
            'preview' => $preview->id,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $preview->revision,
            'device' => 'base',
            'changes' => ['gap' => 24],
        ])->assertForbidden();
    }

    public function test_an_edit_cannot_use_another_projects_preview()
    {
        $this->runningPreview();
        $other = Preview::factory()->editable('abc')->ready()->create();

        $this->actingAs($this->owner)->post(route('visual-edits.store', $this->project), [
            'preview' => $other->id,
            'target' => 'resources/js/pages/Plans.vue:2:5',
            'revision' => $this->repository->head($this->project),
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
            ->get(route('projects.editor.show', $this->project))
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

        $this->get(route('projects.editor.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->where('preview.updating', false));
    }

    public function test_a_failed_rebuild_is_reported_and_keeps_the_old_revision()
    {
        $preview = $this->runningPreview();
        $old = (string) $preview->revision;
        $this->repository->commitFiles($this->project, $old, ['resources/js/pages/Plans.vue' => "<template />\n"], 'Edit', null);
        $this->driver->onExec = fn () => new CommandResult(exitCode: 1, output: '', errorOutput: 'Build failed', durationMs: 5);

        RebuildPreview::dispatchSync($preview);

        $preview->refresh();
        $this->assertSame($old, $preview->revision);
        $this->assertSame('The preview could not show your latest change. Start it again to see it.', $preview->error);
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
        $this->assertSame('<body></body>', $page->getContent());
        $this->assertNull($page->headers->get('Content-Security-Policy'));
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
    protected function runningPreview(array $attributes = []): Preview
    {
        return Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
            ...$attributes,
        ]);
    }
}
