<?php

namespace Tests\Feature\Previews;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewPagesTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);
    }

    public function test_the_owner_sees_the_pages_of_their_app_to_open_one()
    {
        $routes = [
            $this->route('settings/profile', ['web', 'auth']),
            $this->route('/', ['web']),
            $this->route('dashboard', ['web', 'auth', 'verified']),
            $this->route('forgot-password', ['web', 'guest']),
            $this->route('posts/{post}', ['web']),
            $this->route('blog/{page?}', ['web']),
            $this->route('api/user', ['api', 'auth:sanctum']),
            $this->route('admin', ['web', 'auth:admin']),
            $this->route('up', ['web']),
            $this->route('storage/{path}', ['web']),
            $this->route('logout', ['web', 'auth'], 'POST'),
            $this->route('_debugbar/open', ['web']),
            $this->route('broadcasting/auth', ['web']),
            $this->route('user/two-factor-qr-code', ['web', 'auth']),
            $this->route('user/confirmed-password-status', ['web', 'auth']),
            $this->route('passkeys/login/options', ['web']),
        ];
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(
            exitCode: 0,
            output: $command[2] === 'route:list' ? "A notice\n".json_encode($routes) : '',
            errorOutput: '',
            durationMs: 5,
        );

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('pages')->reloadOnly('pages', fn (Assert $page) => $page
                ->where('pages', [
                    ['path' => '/', 'words' => 'Home', 'signed_in' => false],
                    ['path' => '/admin', 'words' => 'Admin', 'signed_in' => true],
                    ['path' => '/blog', 'words' => 'Blog', 'signed_in' => false],
                    ['path' => '/dashboard', 'words' => 'Dashboard', 'signed_in' => true],
                    ['path' => '/forgot-password', 'words' => 'Forgot password', 'signed_in' => false],
                    ['path' => '/settings/profile', 'words' => 'Settings profile', 'signed_in' => true],
                ])));
    }

    public function test_no_pages_show_while_the_app_cannot_be_asked()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 1, output: '', errorOutput: 'Boom', durationMs: 5);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('pages', fn (Assert $page) => $page->where('pages', [])));
    }

    /**
     * A route as `route:list --json` prints it.
     *
     * @param  list<string>  $middleware
     * @return array<string, mixed>
     */
    protected function route(string $uri, array $middleware, string $method = 'GET|HEAD'): array
    {
        return ['domain' => null, 'method' => $method, 'uri' => $uri, 'name' => null, 'action' => 'Closure', 'middleware' => $middleware, 'path' => null];
    }
}
