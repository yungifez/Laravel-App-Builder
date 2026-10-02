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

class PreviewSignInTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    protected Preview $preview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        config(['builder.preview.domain' => 'preview.test', 'builder.preview.public_port' => null]);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        $this->preview = Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);
    }

    public function test_the_owner_sees_who_can_sign_in_to_the_app_on_show()
    {
        $this->driver->onExec = fn (string $workspaceId, array $command) => new CommandResult(
            exitCode: 0,
            output: "Some notice\n".json_encode([['id' => '7', 'name' => 'Ada', 'email' => 'ada@example.test'], ['id' => '3', 'name' => null, 'email' => 'grace@example.test']]),
            errorOutput: '',
            durationMs: 5,
        );

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('people')->reloadOnly('people', fn (Assert $page) => $page
                ->where('people', [
                    ['id' => '7', 'name' => 'Ada', 'email' => 'ada@example.test'],
                    ['id' => '3', 'name' => null, 'email' => 'grace@example.test'],
                ])));

        // Only the person's id and the limit reach the app, as arguments.
        $command = $this->driver->executed[0]['command'];
        $this->assertSame(['php', '-r'], array_slice($command, 0, 2));
        $this->assertSame(['--', '20'], array_slice($command, 3));
    }

    public function test_the_owner_signs_in_as_someone_and_the_preview_host_sets_the_app_session()
    {
        $this->driver->onExec = fn (string $workspaceId, array $command) => new CommandResult(
            exitCode: 0,
            output: json_encode(['name' => 'acme-session', 'value' => 'sealed-session-id', 'minutes' => 90]),
            errorOutput: '',
            durationMs: 5,
        );

        $url = $this->actingAs($this->owner)
            ->postJson(route('preview-sign-ins.store', $this->project), ['person' => '7', 'to' => '/login'])
            ->assertOk()
            ->json('url');

        $this->assertSame(['--', '7'], array_slice($this->driver->executed[0]['command'], 3));
        $this->assertStringStartsWith("http://{$this->preview->host}.preview.test/__builder/session?grant=", $url);

        $exchange = $this->get($url);
        $exchange->assertRedirect('/login');
        $cookie = collect($exchange->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'acme-session');
        $this->assertNotNull($cookie);
        $this->assertSame('sealed-session-id', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('none', $cookie->getSameSite());
        $this->assertTrue($cookie->isPartitioned());

        // The app's cookie goes with that grant only.
        $again = $this->actingAs($this->owner)->get(route('previews.show', $this->preview))->headers->get('Location');
        $this->assertNull(collect($this->get((string) $again)->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'acme-session'));
    }

    public function test_a_person_the_app_cannot_sign_in_is_explained()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 4, output: '', errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->postJson(route('preview-sign-ins.store', $this->project), ['person' => '99'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('app');
    }

    public function test_only_people_who_can_change_the_app_sign_in_to_it_and_only_to_its_own_pages()
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('preview-sign-ins.store', $this->project), ['person' => '7'])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->postJson(route('preview-sign-ins.store', $this->project), ['person' => '7', 'to' => '//evil.test/'])
            ->assertJsonValidationErrors('to');
        $this->assertSame([], $this->driver->executed);
    }

    public function test_the_owner_makes_a_test_person_and_is_signed_in_as_them()
    {
        $this->driver->onExec = fn (string $workspaceId, array $command) => new CommandResult(
            exitCode: 0,
            output: ($command[3] ?? null) === '--'
                ? json_encode(['name' => 'acme-session', 'value' => 'sealed-session-id', 'minutes' => 90])
                : "Some notice\n".json_encode(['id' => '12', 'name' => 'Ada', 'email' => 'ada@example.test']),
            errorOutput: '',
            durationMs: 5,
        );

        $response = $this->actingAs($this->owner)
            ->postJson(route('preview-people.store', $this->project), ['to' => '/classes'])
            ->assertOk()
            ->assertJsonPath('person', ['id' => '12', 'name' => 'Ada', 'email' => 'ada@example.test']);

        // The app makes them with its own factory, then signs in the one made.
        $this->assertStringContainsString('::factory()->create()', $this->driver->executed[0]['command'][2]);
        $this->assertSame(['--', '12'], array_slice($this->driver->executed[1]['command'], 3));
        $this->get((string) $response->json('url'))->assertRedirect('/classes');
    }

    public function test_an_app_that_cannot_make_people_says_to_sign_up_instead()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 5, output: '', errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->postJson(route('preview-people.store', $this->project))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['app' => 'Sign up in your app instead']);

        $this->actingAs(User::factory()->create())
            ->postJson(route('preview-people.store', $this->project))
            ->assertForbidden();
    }
}
