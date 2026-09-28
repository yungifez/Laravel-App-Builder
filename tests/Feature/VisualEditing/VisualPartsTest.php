<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * Copying a part of the page, or taking it out, in the design editor.
 */
class VisualPartsTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const CARD = <<<'VUE'
    <template>
        <div class="flex gap-4 p-4 text-sm">
            <h1 class="text-xl">Plans</h1>
            <p v-if="active">Pick one</p>
            <p v-else>Nothing yet</p>
        </div>
    </template>

    VUE;

    protected const FILE = 'resources/js/pages/Plans.vue';

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
        $this->owner = User::factory()->create(['name' => 'Ada Owner', 'email' => 'ada@example.com']);
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            self::FILE => self::CARD,
        ]), draftNotes: false);
        $this->repository->import($this->project);
        $this->preview = Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);
    }

    public function test_the_owner_copies_a_part_and_the_copy_is_picked_and_can_be_undone()
    {
        $copied = str_replace("<h1 class=\"text-xl\">Plans</h1>\n", "<h1 class=\"text-xl\">Plans</h1>\n        <h1 class=\"text-xl\">Plans</h1>\n", self::CARD);

        $this->actingAs($this->owner)
            ->post(route('visual-parts.store', $this->project), $this->part('3:9'))
            ->assertSessionHasNoErrors();

        $this->assertSame($copied, $this->file());

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame('duplicate', $edit->kind());
        $this->assertSame([4, 9], [$edit->line, $edit->column]);

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->hasFlash('moved', ['target' => self::FILE.':4:9', 'instance' => false])
                ->where('edits.0.kind', 'duplicate'));

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::CARD, $this->file());

        $this->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();
        $this->assertSame($copied, $this->file());
    }

    public function test_the_owner_removes_a_part_and_can_put_it_back()
    {
        $this->actingAs($this->owner)
            ->delete(route('visual-parts.destroy', $this->project), $this->part('3:9'))
            ->assertSessionHasNoErrors();

        $this->assertSame(str_replace("        <h1 class=\"text-xl\">Plans</h1>\n", '', self::CARD), $this->file());

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame('remove', $edit->kind());
        $this->assertSame([2, 5], [$edit->line, $edit->column]);

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::CARD, $this->file());
    }

    public function test_a_part_shown_in_turn_with_another_is_neither_copied_nor_removed()
    {
        $this->actingAs($this->owner)
            ->post(route('visual-parts.store', $this->project), $this->part('4:9'))
            ->assertSessionHasErrors(['edit' => 'This part cannot be copied here. Ask me to copy it instead.']);

        $this->delete(route('visual-parts.destroy', $this->project), $this->part('5:9'))
            ->assertSessionHasErrors(['edit' => 'This part cannot be removed here. Ask me to remove it instead.']);

        $this->assertSame(self::CARD, $this->file());
        $this->assertSame(0, $this->project->visualEdits()->count());
    }

    public function test_only_people_who_may_change_the_app_can_copy_or_remove_its_parts()
    {
        $this->actingAs(User::factory()->create())
            ->delete(route('visual-parts.destroy', $this->project), $this->part('3:9'))
            ->assertForbidden();

        $this->assertSame(self::CARD, $this->file());
    }

    /**
     * The request for one part of the page, by line and column.
     *
     * @return array<string, mixed>
     */
    protected function part(string $at): array
    {
        return [
            'preview' => $this->preview->id,
            'target' => self::FILE.':'.$at,
            'revision' => $this->preview->revision,
        ];
    }

    protected function file(): ?string
    {
        return $this->repository->show($this->project, $this->repository->head($this->project), self::FILE);
    }
}
