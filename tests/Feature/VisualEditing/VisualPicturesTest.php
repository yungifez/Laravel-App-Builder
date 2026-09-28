<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * Putting a new picture in place of one on the page, in the design editor.
 */
class VisualPicturesTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const TEAM = <<<'VUE'
    <template>
        <div class="flex gap-4">
            <img src="/images/ada.jpg" alt="Ada" class="size-16 rounded-full">
            <img :src="member.photo" alt="Member">
        </div>
    </template>

    VUE;

    protected const FILE = 'resources/js/pages/Team.vue';

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
            self::FILE => self::TEAM,
        ]), draftNotes: false);
        $this->repository->import($this->project);
        $this->preview = Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);
    }

    public function test_the_owner_puts_in_a_new_picture_and_can_undo_and_redo_it()
    {
        $picture = UploadedFile::fake()->image('beach.png', 40, 30);
        $contents = (string) $picture->get();
        $path = 'public/images/'.substr(hash('sha256', $contents), 0, 16).'.png';
        $shown = str_replace('/images/ada.jpg', '/'.substr($path, strlen('public/')), self::TEAM);

        $this->actingAs($this->owner)
            ->post(route('visual-pictures.store', $this->project), $this->picture('3:9', $picture))
            ->assertSessionHasNoErrors();

        $this->assertSame($shown, $this->file());
        $this->assertSame($contents, $this->file($path));

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame('picture', $edit->kind());

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page
                ->where('edits.0.kind', 'picture')
                ->where('edits.0.picture', '/'.substr($path, strlen('public/'))));

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::TEAM, $this->file());

        $this->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();
        $this->assertSame($shown, $this->file());
    }

    public function test_the_design_panel_learns_which_pictures_can_be_changed_here()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => self::FILE.':3:9']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.picture', ['src' => '/images/ada.jpg'])));

        $this->get(route('projects.show', ['project' => $this->project, 'target' => self::FILE.':4:9']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', fn (Assert $page) => $page
                ->where('element.picture', ['src' => null])));
    }

    public function test_a_picture_the_app_chooses_is_left_to_the_coding_agent()
    {
        $this->actingAs($this->owner)
            ->post(route('visual-pictures.store', $this->project), $this->picture('4:9', UploadedFile::fake()->image('beach.png'), before: 'member.photo'))
            ->assertSessionHasErrors(['edit' => 'Your app decides which picture shows here, so I can\'t change it here. Ask me to change it instead.']);

        $this->assertSame(self::TEAM, $this->file());
        $this->assertSame(0, $this->project->visualEdits()->count());
    }

    public function test_a_picture_changed_since_is_not_overwritten()
    {
        $this->actingAs($this->owner)
            ->post(route('visual-pictures.store', $this->project), $this->picture('3:9', UploadedFile::fake()->image('beach.png'), before: '/images/old.jpg'))
            ->assertSessionHasErrors(['edit' => 'This picture was changed since. Look again and try once more.']);

        $this->assertSame(self::TEAM, $this->file());
    }

    public function test_only_photos_and_pictures_are_put_in()
    {
        $drawing = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($this->owner)
            ->post(route('visual-pictures.store', $this->project), $this->picture('3:9', $drawing))
            ->assertSessionHasErrors('picture');

        $this->post(route('visual-pictures.store', $this->project), $this->picture('3:9', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')))
            ->assertSessionHasErrors('picture');

        $this->assertSame(self::TEAM, $this->file());
    }

    public function test_only_people_who_may_change_the_app_can_put_in_a_picture()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('visual-pictures.store', $this->project), $this->picture('3:9', UploadedFile::fake()->image('beach.png')))
            ->assertForbidden();

        $this->assertSame(self::TEAM, $this->file());
    }

    /**
     * The request for a new picture in one part of the page, by line and
     * column.
     *
     * @return array<string, mixed>
     */
    protected function picture(string $at, UploadedFile $picture, string $before = '/images/ada.jpg'): array
    {
        return [
            'preview' => $this->preview->id,
            'target' => self::FILE.':'.$at,
            'before' => $before,
            'picture' => $picture,
            'revision' => $this->preview->revision,
        ];
    }

    protected function file(string $path = self::FILE): ?string
    {
        return $this->repository->show($this->project, $this->repository->head($this->project), $path);
    }
}
