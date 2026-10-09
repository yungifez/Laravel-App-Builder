<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\VisualEditing\DesignDrafts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * The owner changes how a part moves with ready-made choices, and the
 * classes are written without a model.
 */
class VisualMotionTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const PAGE = <<<'VUE'
    <template>
        <div class="p-4">
            <h1 class="text-xl">Plans</h1>
            <img class="opacity-100 transition-all delay-300 duration-750 starting:opacity-0 motion-safe:starting:-translate-x-[51px]" src="/logo.svg" />
            <span class="animate-[wiggle_1s_ease-in-out_infinite]">New</span>
        </div>
    </template>

    VUE;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected Preview $preview;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            'resources/js/pages/Plans.vue' => self::PAGE,
        ]), draftNotes: false);
        $this->repository->import($this->project);
        $this->preview = Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);
    }

    public function test_inspecting_a_part_says_how_it_moves_and_what_suits_it()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => 'resources/js/pages/Plans.vue:4:9']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.motion.entrance', 'slide')
                ->where('element.motion.words', 'Slides in from the left, slowly, after a wait.')
                ->where('element.motion.suggested', ['entrance' => 'fade'])));

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => 'resources/js/pages/Plans.vue:3:9']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.motion.moves', false)
                ->where('element.motion.suggested', ['entrance' => 'rise'])));
    }

    public function test_a_chosen_motion_is_written_into_the_draft_and_undone_like_a_look()
    {
        $this->move('resources/js/pages/Plans.vue:3:9', 'text-xl', ['entrance' => 'rise', 'speed' => 'normal', 'wait' => 'none', 'hover' => 'none', 'loop' => 'none'])
            ->assertSessionHasNoErrors();

        $branch = app(DesignDrafts::class)->find($this->project)?->designBranch();
        $contents = (string) $this->repository->show($this->project, $this->repository->head($this->project, $branch), 'resources/js/pages/Plans.vue');
        $this->assertStringContainsString('<h1 class="text-xl transition-all duration-500 ease-out starting:opacity-0 motion-safe:starting:translate-y-4">', $contents);

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame('motion', $edit->kind());
        $this->assertStringStartsWith('Change how a heading moves', $this->repository->log($this->project, 1, $branch)[0]['subject']);

        $this->actingAs($this->owner)
            ->post(route('visual-edits.reversion.store', $edit))
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('<h1 class="text-xl">', (string) $this->repository->show($this->project, $this->repository->head($this->project, $branch), 'resources/js/pages/Plans.vue'));
    }

    public function test_motion_of_its_own_is_left_to_the_agent()
    {
        $this->move('resources/js/pages/Plans.vue:5:9', 'animate-[wiggle_1s_ease-in-out_infinite]', ['entrance' => 'fade', 'speed' => 'normal', 'wait' => 'none', 'hover' => 'none', 'loop' => 'none'])
            ->assertSessionHasErrors(['edit' => 'This part moves in a way of its own. Ask me to change how it moves.']);
    }

    public function test_only_the_ready_made_choices_are_taken()
    {
        $this->move('resources/js/pages/Plans.vue:3:9', 'text-xl', ['entrance' => 'wobble', 'speed' => 'normal', 'wait' => 'none', 'hover' => 'none', 'loop' => 'none'])
            ->assertSessionHasErrors('motion.entrance');
    }

    public function test_others_cannot_change_how_a_part_moves()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('visual-motions.store', $this->project), [])
            ->assertForbidden();
    }

    /**
     * @param  array<string, string>  $motion
     */
    protected function move(string $target, string $expected, array $motion): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('visual-motions.store', $this->project), [
            'preview' => $this->preview->uuid,
            'target' => $target,
            'revision' => $this->repository->head($this->project, $this->preview->refresh()->branch()),
            'expected' => $expected,
            'motion' => $motion,
        ]);
    }
}
