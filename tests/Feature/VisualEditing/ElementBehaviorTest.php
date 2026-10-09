<?php

namespace Tests\Feature\VisualEditing;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

/**
 * A selected part names the server action it starts through Wayfinder, as
 * the app on show lists its routes, and never a guess.
 */
class ElementBehaviorTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected const PROFILE = <<<'VUE'
    <script setup lang="ts">
    import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
    import { edit as editTeam } from '@/routes/teams';
    import { Form } from '@inertiajs/vue3';
    </script>

    <template>
        <div>
            <Form v-bind="ProfileController.destroy.form()" class="space-y-6">
                <button class="px-3">Delete account</button>
                <button type="button" class="px-3">Keep it</button>
            </Form>
            <a :href="editTeam()" class="underline">Team</a>
            <a href="/help" class="underline">Help</a>
        </div>
    </template>

    VUE;

    protected const ROUTES = [
        ['name' => 'profile.destroy', 'action' => 'App\Http\Controllers\Settings\ProfileController@destroy'],
        ['name' => 'teams.edit', 'action' => 'App\Http\Controllers\TeamController@edit'],
        ['name' => 'login', 'action' => 'Laravel\Fortify\Http\Controllers\AuthenticatedSessionController@create'],
    ];

    protected FakeWorkspaceDriver $driver;

    protected ProjectRepository $repository;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->repository = app(ProjectRepository::class);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([
            'resources/js/pages/settings/Profile.vue' => self::PROFILE,
        ]), draftNotes: false);
        $this->repository->import($this->project);

        config(['builder.preview.workspace_driver' => 'fake', 'builder.preview.domain' => 'preview.test']);
    }

    public function test_a_button_that_sends_a_wayfinder_form_and_a_link_to_a_named_route_name_their_action()
    {
        $this->listRoutes(self::ROUTES);

        $this->inspect('resources/js/pages/settings/Profile.vue:10:13', fn (Assert $page) => $page
            ->where('element.behavior', ['key' => 'App\Http\Controllers\Settings\ProfileController@destroy', 'route' => 'profile.destroy', 'reason' => null]));

        $this->inspect('resources/js/pages/settings/Profile.vue:13:9', fn (Assert $page) => $page
            ->where('element.behavior', ['key' => 'App\Http\Controllers\TeamController@edit', 'route' => 'teams.edit', 'reason' => null]));
    }

    public function test_a_plain_link_or_a_button_that_does_not_send_the_form_names_no_action_and_says_so()
    {
        $this->listRoutes(self::ROUTES);

        $this->inspect('resources/js/pages/settings/Profile.vue:14:9', fn (Assert $page) => $page
            ->where('element.behavior', ['key' => null, 'route' => null, 'reason' => 'not_bound']));

        $this->inspect('resources/js/pages/settings/Profile.vue:11:13', fn (Assert $page) => $page
            ->where('element.behavior', ['key' => null, 'route' => null, 'reason' => 'not_bound']));

        // Nothing to look up, so the app is not asked for its routes.
        $this->assertNotContains('route:list', collect($this->driver->executed)->pluck('command')->flatten()->all());
    }

    public function test_a_call_imported_from_where_this_apps_wayfinder_does_not_write_names_no_action()
    {
        $this->listRoutes(self::ROUTES);

        // "@" stands for another folder in this app, so "@/actions/…" is
        // not Wayfinder's, though the routes have an action of that name.
        $this->repository->commitFiles($this->project, $this->repository->head($this->project), [
            'tsconfig.json' => "{\n    \"compilerOptions\": { \"paths\": { \"@/*\": [\"./resources/ts/*\"] } }\n}\n",
        ], 'Move the alias', null);

        $this->inspect('resources/js/pages/settings/Profile.vue:10:13', fn (Assert $page) => $page
            ->where('element.behavior', ['key' => null, 'route' => null, 'reason' => 'not_found']));
    }

    public function test_an_action_the_app_no_longer_has_names_no_action_and_the_part_still_opens()
    {
        // The controller method was removed since the page was written.
        $this->listRoutes([self::ROUTES[1], self::ROUTES[2]]);

        $this->inspect('resources/js/pages/settings/Profile.vue:10:13', fn (Assert $page) => $page
            ->where('element.tag', 'button')
            ->where('element.behavior', ['key' => null, 'route' => null, 'reason' => 'not_found']));

        // An app that cannot list its routes has none to match.
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(exitCode: 1, output: '', errorOutput: 'Class not found', durationMs: 5);

        $this->inspect('resources/js/pages/settings/Profile.vue:13:9', fn (Assert $page) => $page
            ->where('element.behavior.key', null)
            ->where('element.behavior.reason', 'not_found'));
    }

    /**
     * @param  list<array{name: string|null, action: string}>  $routes
     */
    protected function listRoutes(array $routes): void
    {
        $this->driver->onExec = fn (string $id, array $command) => new CommandResult(
            exitCode: 0,
            output: in_array('route:list', $command, true) ? json_encode(array_map(fn (array $route) => [...$route, 'method' => 'GET|HEAD', 'uri' => 'x', 'middleware' => ['web']], $routes)) : '',
            errorOutput: '',
            durationMs: 5,
        );
    }

    protected function inspect(string $target, Closure $assert): void
    {
        Preview::query()->delete();
        Preview::factory()->editable($this->repository->head($this->project))->ready()->create([
            'project_id' => $this->project->id,
            'workspace_id' => Workspace::factory()->create(['user_id' => $this->owner->id])->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'target' => $target]))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('element', $assert));
    }
}
