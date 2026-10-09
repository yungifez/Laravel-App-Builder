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
        <span>{{ $t('Missing') }}</span>
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

    protected const WELCOME = <<<'BLADE'
    <main>
        <h1>{{ __('Welcome') }}</h1>
        <p>{{ __('auth.failed') }}</p>
    </main>

    BLADE;

    protected const WORDS = <<<'JSON'
    {
      "Save": "Enregistrer",
      "Cancel": "Annuler"
    }

    JSON;

    protected const AUTH = <<<'PHP'
    <?php

    return [
        'failed' => 'Ces identifiants ne correspondent pas.',
        'throttle' => 'Trop d\'essais.',
    ];

    PHP;

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
            'resources/views/welcome.blade.php' => self::WELCOME,
            'config/app.php' => "<?php\n\nreturn [\n    'locale' => env('APP_LOCALE', 'fr'),\n];\n",
            'lang/fr.json' => self::WORDS,
            'lang/fr/auth.php' => self::AUTH,
        ]), draftNotes: false);
        $this->repository->import($this->project);
        $this->preview = Preview::factory()->editable($this->editedHead())->ready()->create([
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

    public function test_words_set_in_a_page_script_are_changed_there()
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
    }

    public function test_translated_words_are_changed_in_the_apps_language_file_and_the_key_stays()
    {
        $this->reword([
            'target' => 'resources/js/pages/Settings.vue:5:5',
            'before' => 'Enregistrer',
            'text' => 'Garder "tout"',
        ])->assertSessionHasNoErrors();

        $reworded = str_replace('"Enregistrer"', '"Garder \\"tout\\""', self::WORDS);
        $this->assertSame($reworded, $this->fileNow('lang/fr.json'));
        $this->assertSame(self::SETTINGS, $this->fileNow('resources/js/pages/Settings.vue'));

        $edit = $this->project->visualEdits()->sole();
        $this->assertSame(['lang/fr.json', 2, 11, 'button'], [$edit->file, $edit->line, $edit->column, $edit->tag]);

        $this->post(route('visual-edits.reversion.store', $edit))->assertSessionHasNoErrors();
        $this->assertSame(self::WORDS, $this->fileNow('lang/fr.json'));
    }

    public function test_a_named_key_is_changed_in_its_file_and_a_blade_key_with_no_words_gets_them()
    {
        $this->reword([
            'target' => 'resources/views/welcome.blade.php:3:5',
            'before' => 'Ces identifiants ne correspondent pas.',
            'text' => "Ce n'est pas le bon mot de passe.",
        ])->assertSessionHasNoErrors();
        $this->assertStringContainsString("'failed' => 'Ce n\\'est pas le bon mot de passe.',", $this->fileNow('lang/fr/auth.php'));
        $this->assertStringContainsString("'throttle' => 'Trop d\\'essais.',", $this->fileNow('lang/fr/auth.php'));

        // Laravel shows a key it has no words for as written.
        $this->reword([
            'target' => 'resources/views/welcome.blade.php:2:5',
            'before' => 'Welcome',
            'text' => 'Bienvenue',
            'revision' => $this->editedHead(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Save' => 'Enregistrer', 'Cancel' => 'Annuler', 'Welcome' => 'Bienvenue'], json_decode($this->fileNow('lang/fr.json'), true));
        $this->assertStringContainsString("  \"Cancel\": \"Annuler\",\n  \"Welcome\": \"Bienvenue\"\n}\n", $this->fileNow('lang/fr.json'));
        $this->assertSame(self::WELCOME, $this->fileNow('resources/views/welcome.blade.php'));
    }

    public function test_translated_words_that_cannot_be_found_or_changed_since_are_left_alone()
    {
        $this->reword(['target' => 'resources/js/pages/Settings.vue:5:5', 'before' => 'Save', 'text' => 'Garder'])
            ->assertSessionHasErrors(['edit' => 'These words were changed since. Look again and try once more.']);

        // An app in a language it keeps no words for. A script's lookup may
        // not read a file Laravel would make.
        $head = $this->repository->commitFiles($this->project, $this->editedHead(), [
            'config/app.php' => "<?php\n\nreturn [\n    'locale' => 'de',\n];\n",
        ], 'Speak German', null, app(DesignDrafts::class)->find($this->project)?->designBranch());

        $this->reword(['target' => 'resources/js/pages/Settings.vue:6:5', 'before' => 'Missing', 'text' => 'Fehlt', 'revision' => $head])
            ->assertSessionHasErrors(['edit' => 'These words come from your app\'s translations, but I can\'t find where. Ask me to change them instead.']);

        $this->assertSame($head, $this->editedHead());
    }

    public function test_words_that_cannot_be_told_apart_or_come_from_data_are_left_alone()
    {
        $head = $this->editedHead();

        $this->reword(['target' => 'resources/js/pages/Settings.vue:3:5', 'before' => 'Pick one', 'text' => 'Choose'])
            ->assertSessionHasErrors(['edit' => 'These words are written in more than one place, so I can\'t tell which to change. Ask me to change them instead.']);
        $this->reword(['target' => 'resources/js/pages/Settings.vue:4:5', 'before' => 'Ada', 'text' => 'Grace'])
            ->assertSessionHasErrors(['edit' => 'These words come from your app\'s data or code, so I can\'t change them here. Ask me to change them instead.']);
        $this->reword(['target' => 'resources/js/layouts/AuthLayout.vue:2:5', 'before' => 'Log in to your account', 'text' => "Don't wait", 'places' => ['resources/js/pages/Login.vue']])
            ->assertSessionHasErrors(['edit' => 'These words can\'t hold quotes, backslashes or line breaks. Ask me to change them instead.']);
        $this->reword(['target' => 'resources/js/components/Heading.vue:3:9', 'before' => 'Settings', 'text' => 'Mine', 'places' => ['../secrets.vue', '/etc/app.vue', 'config/app.php']])
            ->assertSessionHasErrors(['places.0', 'places.1', 'places.2']);

        $this->assertSame($head, $this->editedHead());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function reword(array $data): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('visual-texts.store', $this->project), $data + [
            'preview' => $this->preview->uuid,
            'revision' => $this->preview->revision,
        ]);
    }

    protected function fileNow(string $file): string
    {
        return (string) $this->repository->show($this->project, $this->editedHead(), $file);
    }

    /**
     * Get the newest commit of what the owner edits: the app's design
     * draft while one waits, else the app.
     */
    protected function editedHead(): string
    {
        return $this->repository->head($this->project, app(DesignDrafts::class)->find($this->project)?->designBranch());
    }
}
