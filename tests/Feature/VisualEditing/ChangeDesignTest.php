<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Features\OpenChangeForDesign;
use App\Actions\Projects\CreateProject;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Jobs\RebuildPreview;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\VisualEdit;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

/**
 * The owner designs a change on its copy before keeping it: the edits go
 * into the change, never into the app, and keeping it keeps them.
 */
class ChangeDesignTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const CARD = <<<'VUE'
    <template>
        <div class="flex gap-4 p-4 text-sm">
            <h1 class="text-xl">Plans</h1>
            <p>Pick one</p>
        </div>
    </template>

    VUE;

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

    public function test_the_copy_of_a_waiting_change_runs_from_its_own_branch_and_can_be_designed()
    {
        Http::fake(['*/up' => Http::response('ok')]);
        $main = $this->repository->head($this->project);
        $change = $this->waitingChange();

        $this->actingAs($this->owner)
            ->post(route('feature-requests.previews.store', $change))
            ->assertSessionHasNoErrors();

        $preview = $change->previews()->sole();
        $this->assertSame(PreviewStatus::Ready, $preview->status);
        $this->assertTrue($preview->editable);
        $this->assertSame($this->repository->head($this->project, $change->designBranch()), $preview->revision);
        $this->assertStringContainsString('<p>Pick a plan</p>', (string) $this->repository->show($this->project, (string) $preview->revision, 'resources/js/pages/Plans.vue'));
        $this->assertSame($main, $change->refresh()->design_base);

        // The branch holds the change, so the copy applies no patches.
        $this->assertSame([], array_filter(array_keys($this->driver->files), fn (string $key) => str_contains($key, FeatureRequest::LINEAGE_DIRECTORY)));
        $this->assertSame($main, $this->repository->head($this->project), 'The app itself does not move.');
    }

    public function test_a_design_edit_on_the_copy_goes_into_the_change_and_not_the_app()
    {
        Queue::fake();
        $main = $this->repository->head($this->project);
        $change = $this->waitingChange();
        $preview = $this->runningCopy($change);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.store', $this->project), [
                'preview' => $preview->uuid,
                'target' => 'resources/js/pages/Plans.vue:2:5',
                'revision' => $preview->revision,
                'expected' => 'flex gap-4 p-4 text-sm',
                'device' => 'md',
                'changes' => ['gap' => 24],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($main, $this->repository->head($this->project));
        $this->assertStringContainsString('md:gap-6', (string) $this->repository->show($this->project, $this->repository->head($this->project, $change->designBranch()), 'resources/js/pages/Plans.vue'));

        $patch = (string) $change->refresh()->patch;
        $this->assertStringContainsString('+    <div class="flex gap-4 p-4 text-sm md:gap-6">', $patch);
        $this->assertStringContainsString('+        <p>Pick a plan</p>', $patch);

        $edit = $this->project->visualEdits()->sole();
        $this->assertTrue($edit->featureRequest->is($change));
        $this->assertSame($change->designBranch(), $edit->branch());
        Queue::assertPushed(RebuildPreview::class, fn (RebuildPreview $job) => $job->preview->is($preview));
    }

    public function test_undoing_a_design_edit_on_the_change_takes_it_back_out_of_the_change()
    {
        Queue::fake();
        $change = $this->waitingChange();
        $preview = $this->runningCopy($change);
        $edit = $this->designEdit($preview);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.reversion.store', $edit))
            ->assertSessionHasNoErrors();

        $patch = (string) $change->refresh()->patch;
        $this->assertStringNotContainsString('md:gap-6', $patch);
        $this->assertStringContainsString('+        <p>Pick a plan</p>', $patch);
    }

    public function test_keeping_the_change_keeps_the_design_edits_made_on_it()
    {
        Queue::fake();
        $change = $this->waitingChange();
        Run::factory()->for($change)->create(['status' => RunStatus::Completed]);
        $this->designEdit($this->runningCopy($change));

        $this->actingAs($this->owner)
            ->post(route('feature-requests.acceptance.store', $change))
            ->assertSessionHasNoErrors();

        $kept = (string) $this->repository->show($this->project, $this->repository->head($this->project), 'resources/js/pages/Plans.vue');
        $this->assertStringContainsString('<div class="flex gap-4 p-4 text-sm md:gap-6">', $kept);
        $this->assertStringContainsString('<p>Pick a plan</p>', $kept);
    }

    public function test_picking_a_part_while_trying_the_change_inspects_the_copy()
    {
        $change = $this->waitingChange();
        $preview = $this->runningCopy($change);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'copy' => $change->uuid, 'target' => 'resources/js/pages/Plans.vue:4:9']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.tag', 'p')
                ->where('element.revision', $preview->revision)));
    }

    public function test_the_app_and_a_change_that_follows_another_keep_their_own_branches()
    {
        $parent = $this->waitingChange();
        $child = FeatureRequest::factory()->generated()->for($this->project)->create([
            'parent_id' => $parent->id,
            'base_revision' => $parent->base_revision,
            'patch' => $this->patchFor(fn (string $branch) => $this->repository->commitFiles($this->project, $this->repository->head($this->project, $branch), ['resources/js/pages/Teams.vue' => "<template>\n    <p>Teams</p>\n</template>\n"], 'Child', null, $branch)),
        ]);

        $head = app(OpenChangeForDesign::class)->handle($child);

        $this->assertStringContainsString('Pick a plan', (string) $this->repository->show($this->project, $head, 'resources/js/pages/Plans.vue'));
        $this->assertNotNull($this->repository->show($this->project, $head, 'resources/js/pages/Teams.vue'));
        $this->assertStringNotContainsString('Pick a plan', $this->repository->patch($this->project, (string) $child->refresh()->design_base, $head), 'The parent is under the child, not in its patch.');
        $this->assertSame("changes/{$child->id}", $child->designBranch());
    }

    /**
     * A change that rewrites the card's words, waiting to be kept.
     */
    protected function waitingChange(): FeatureRequest
    {
        return FeatureRequest::factory()->generated()->for($this->project)->create([
            'base_revision' => $this->repository->head($this->project),
            'patch' => $this->patchFor(fn (string $branch) => $this->repository->commitFiles($this->project, $this->repository->head($this->project, $branch), ['resources/js/pages/Plans.vue' => str_replace('Pick one', 'Pick a plan', self::CARD)], 'Change', null, $branch)),
        ]);
    }

    /**
     * Make a patch the way a change keeps one, on a branch thrown away after.
     */
    protected function patchFor(callable $commit): string
    {
        return Event::fakeFor(function () use ($commit) {
            $base = $this->repository->head($this->project);
            $this->repository->createBranch($this->project, 'scratch', $base);
            $commit('scratch');
            $patch = $this->repository->patch($this->project, $base, $this->repository->head($this->project, 'scratch'));
            $this->repository->deleteBranch($this->project, 'scratch', $this->project->branch());

            return $patch;
        });
    }

    protected function runningCopy(FeatureRequest $change): Preview
    {
        return Preview::factory()->editable(app(OpenChangeForDesign::class)->handle($change))->ready()->create([
            'project_id' => $this->project->id,
            'feature_request_id' => $change->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);
    }

    protected function designEdit(Preview $preview): VisualEdit
    {
        $this->actingAs($this->owner)
            ->post(route('visual-edits.store', $this->project), [
                'preview' => $preview->uuid,
                'target' => 'resources/js/pages/Plans.vue:2:5',
                'revision' => $preview->revision,
                'expected' => 'flex gap-4 p-4 text-sm',
                'device' => 'md',
                'changes' => ['gap' => 24],
            ])
            ->assertSessionHasNoErrors();

        return $this->project->visualEdits()->latest('id')->firstOrFail();
    }
}
