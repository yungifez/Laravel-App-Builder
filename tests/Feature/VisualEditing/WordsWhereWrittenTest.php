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
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

class WordsWhereWrittenTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const HEADING = <<<'VUE'
    <template>
        <header>
            <h2 class="text-xl">{{ title }}</h2>
        </header>
    </template>

    VUE;

    protected const SETTINGS = <<<'VUE'
    <template>
        <Heading title="Settings" />
        <p>{{ note }}</p>
        <p>{{ user.name }}</p>
        <button>{{ __('Save') }}</button>
    </template>

    <script setup>
    const note = 'Pick one';
    const hint = 'Pick one';
    </script>

    VUE;

    protected const LAYOUT = <<<'VUE'
    <template>
        <h1>{{ title }}</h1>
        <slot />
    </template>

    VUE;

    protected const LOGIN = <<<'VUE'
    <script setup>
    defineOptions({ layout: { title: 'Log in to your account' } });
    </script>

    <template>
        <Head title="Log in to your account" />
        <form>Email</form>
    </template>

    VUE;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected Preview $preview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWorkspaces();
        Queue::fake();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            'resources/js/components/Heading.vue' => self::HEADING,
            'resources/js/pages/Settings.vue' => self::SETTINGS,
            'resources/js/layouts/AuthLayout.vue' => self::LAYOUT,
            'resources/js/pages/Login.vue' => self::LOGIN,
        ]), draftNotes: false);
        $this->repository->import($this->project);
        $this->preview = Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);
    }

    public function test_words_passed_to_a_component_are_changed_where_it_is_used_and_can_be_undone()
    {
        $this->reword([
            'target' => 'resources/js/components/Heading.vue:3:9',
            'before' => 'Settings',
            'text' => 'Your settings',
            'places' => ['resources/js/components/Heading.vue', 'resources/js/pages/Settings.vue'],
        ])->assertSessionHasNoErrors();

        $reworded = str_replace('title="Settings"', 'title="Your settings"', self::SETTINGS);
        $this->assertSame($reworded, $this->fileNow('resources/js/pages/Settings.vue'));
        $this->assertSame(self::HEADING, $this->fileNow('resources/js/components/Heading.vue'));

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame(['resources/js/pages/Settings.vue', 2, 21, 'h2'], [$edit->file, $edit->line, $edit->column, $edit->tag]);
        $this->assertTrue($edit->rewords());

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::SETTINGS, $this->fileNow('resources/js/pages/Settings.vue'));

        $this->delete(route('visual-edits.reversion.destroy', $edit))->assertSessionHasNoErrors();
        $this->assertSame($reworded, $this->fileNow('resources/js/pages/Settings.vue'));
    }

    public function test_words_set_in_a_page_script_or_looked_up_as_a_translation_are_changed_there()
    {
        // The page is not around the layout's heading, but it is drawn on
        // the same page.
        $this->reword([
            'target' => 'resources/js/layouts/AuthLayout.vue:2:5',
            'before' => 'Log in to your account',
            'text' => 'Welcome back',
            'places' => ['resources/js/layouts/AuthLayout.vue', 'resources/js/pages/Login.vue'],
        ])->assertSessionHasNoErrors();
        // The browser tab's title, written the same, is not what was shown.
        $this->assertStringContainsString("layout: { title: 'Welcome back' }", $this->fileNow('resources/js/pages/Login.vue'));
        $this->assertStringContainsString('<Head title="Log in to your account" />', $this->fileNow('resources/js/pages/Login.vue'));

        $this->reword([
            'target' => 'resources/js/pages/Settings.vue:5:5',
            'before' => 'Save',
            'text' => 'Keep changes',
            'revision' => $this->repository->head($this->project),
        ])->assertSessionHasNoErrors();
        $this->assertStringContainsString("<button>{{ __('Keep changes') }}</button>", $this->fileNow('resources/js/pages/Settings.vue'));
    }

    public function test_words_that_cannot_be_told_apart_or_come_from_data_are_left_alone()
    {
        $head = $this->repository->head($this->project);

        $this->reword(['target' => 'resources/js/pages/Settings.vue:3:5', 'before' => 'Pick one', 'text' => 'Choose'])
            ->assertSessionHasErrors(['edit' => 'These words are written in more than one place, so I can\'t tell which to change. Ask me to change them instead.']);
        $this->reword(['target' => 'resources/js/pages/Settings.vue:4:5', 'before' => 'Ada', 'text' => 'Grace'])
            ->assertSessionHasErrors(['edit' => 'These words come from your app\'s data or code, so I can\'t change them here. Ask me to change them instead.']);
        $this->reword(['target' => 'resources/js/pages/Settings.vue:5:5', 'before' => 'Save', 'text' => "Don't save"])
            ->assertSessionHasErrors(['edit' => 'These words can\'t hold quotes, backslashes or line breaks. Ask me to change them instead.']);
        $this->reword(['target' => 'resources/js/components/Heading.vue:3:9', 'before' => 'Settings', 'text' => 'Mine', 'places' => ['../secrets.vue', '/etc/app.vue', 'config/app.php']])
            ->assertSessionHasErrors(['places.0', 'places.1', 'places.2']);

        $this->assertSame($head, $this->repository->head($this->project));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function reword(array $data): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('visual-texts.store', $this->project), $data + [
            'preview' => $this->preview->id,
            'revision' => $this->preview->revision,
        ]);
    }

    protected function fileNow(string $file): string
    {
        return (string) $this->repository->show($this->project, $this->repository->head($this->project), $file);
    }
}
