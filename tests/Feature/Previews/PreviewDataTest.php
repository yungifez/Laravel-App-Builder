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

class PreviewDataTest extends TestCase
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

    public function test_the_owner_sees_the_tables_their_app_saved_to_their_own_first()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 0, output: "Some notice\n".json_encode(['tables' => [
            ['table' => 'sessions', 'rows' => 3],
            ['table' => 'users', 'rows' => 2],
            ['table' => 'class_bookings', 'rows' => 7],
            ['table' => 'migrations', 'rows' => 9],
        ]]), errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('data')->reloadOnly('data', fn (Assert $page) => $page
                ->where('data', [
                    ['name' => 'class_bookings', 'words' => 'Class bookings', 'rows' => 7, 'own' => true],
                    ['name' => 'users', 'words' => 'Users', 'rows' => 2, 'own' => true],
                    ['name' => 'migrations', 'words' => 'Migrations', 'rows' => 9, 'own' => false],
                    ['name' => 'sessions', 'words' => 'Sessions', 'rows' => 3, 'own' => false],
                ])));

        // Read through the app itself, with the settings it runs with.
        $this->assertSame(['php', 'artisan', 'db:show', '--json', '--counts', '--no-interaction'], $this->driver->executed[0]['command']);
        $this->assertSame('log', $this->driver->environments[0]['MAIL_MAILER']);
    }

    public function test_the_owner_reads_the_rows_of_a_table_with_what_visitors_sign_in_with_hidden()
    {
        $rows = collect(range(51, 1))->map(fn (int $id) => ['id' => $id, 'name' => "Member {$id}", 'password' => 'secret-hash', 'bio' => str_repeat('a', 300), 'deleted_at' => null])->all();
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: 0, output: $command[1] === '-r'
            ? json_encode(['columns' => ['id', 'name', 'password', 'bio', 'deleted_at'], 'key' => 'id', 'rows' => $rows])
            : json_encode(['tables' => [['table' => 'members', 'rows' => 51]]]), errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'table' => 'members']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('rows', fn (Assert $page) => $page
                ->where('rows.name', 'members')
                ->where('rows.words', 'Members')
                ->where('rows.columns', ['id', 'name', 'password', 'bio', 'deleted_at'])
                ->count('rows.rows', 50)
                ->where('rows.rows.0', ['51', 'Member 51', '••••••', str_repeat('a', 200).'...', null])
                ->where('rows.key', 'id')
                ->where('rows.ids.0', '51')
                ->where('rows.more', true)));

        // The table's name is passed to the app, never written into code.
        $read = collect($this->driver->executed)->firstWhere('command.1', '-r');
        $this->assertSame(['--', 'members', '50'], array_slice($read['command'], 3));
    }

    public function test_a_table_the_app_does_not_have_is_not_read()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 0, output: json_encode(['tables' => [['table' => 'members', 'rows' => 1]]]), errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['project' => $this->project, 'table' => 'members; drop table members']))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('rows', fn (Assert $page) => $page->where('rows', null)));
        $this->assertNull(collect($this->driver->executed)->firstWhere('command.1', '-r'));
    }

    public function test_the_owner_deletes_one_row_through_the_app()
    {
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: 0, output: $command[1] === '-r'
            ? "Some notice\n".json_encode(['deleted' => 1, 'linked' => false])
            : json_encode(['tables' => [['table' => 'members', 'rows' => 2]]]), errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->from(route('projects.show', $this->project))
            ->delete(route('preview-rows.destroy', $this->project), ['table' => 'members', 'row' => '7'])
            ->assertRedirect(route('projects.show', $this->project))
            ->assertSessionHasNoErrors();

        // The table and the row are passed to the app, never written into code.
        $deleted = collect($this->driver->executed)->firstWhere('command.1', '-r');
        $this->assertSame(['--', 'members', '7'], array_slice($deleted['command'], 3));
    }

    public function test_a_row_other_data_points_to_is_kept_and_told()
    {
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(exitCode: 0, output: $command[1] === '-r'
            ? json_encode(['deleted' => 0, 'linked' => true])
            : json_encode(['tables' => [['table' => 'members', 'rows' => 2]]]), errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->delete(route('preview-rows.destroy', $this->project), ['table' => 'members', 'row' => '7'])
            ->assertSessionHasErrors(['app' => 'Your app kept it, because other saved data still points to it. Delete that first.']);
    }

    public function test_only_the_owner_deletes_and_only_from_the_apps_own_tables()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 0, output: json_encode(['tables' => [['table' => 'members', 'rows' => 1]]]), errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->delete(route('preview-rows.destroy', $this->project), ['table' => 'members; drop table members', 'row' => '7'])
            ->assertSessionHasErrors('table');

        $this->actingAs(User::factory()->create())
            ->delete(route('preview-rows.destroy', $this->project), ['table' => 'members', 'row' => '7'])
            ->assertForbidden();
        $this->assertNull(collect($this->driver->executed)->firstWhere('command.1', '-r'));
    }

    public function test_the_owner_starts_the_data_again_with_examples_or_empty()
    {
        $this->actingAs($this->owner)
            ->from(route('projects.show', $this->project))
            ->put(route('preview-data.update', $this->project), ['with' => 'examples'])
            ->assertRedirect(route('projects.show', $this->project))
            ->assertSessionHasNoErrors();
        $this->put(route('preview-data.update', $this->project), ['with' => 'empty'])->assertSessionHasNoErrors();

        $this->assertSame(['php', 'artisan', 'migrate:fresh', '--force', '--no-interaction', '--seed'], $this->driver->executed[0]['command']);
        $this->assertSame(['php', 'artisan', 'migrate:fresh', '--force', '--no-interaction'], $this->driver->executed[1]['command']);
        $this->assertSame('local', $this->driver->environments[0]['APP_ENV']);
    }

    public function test_a_failure_is_told_in_plain_words_and_only_the_owner_may_start_again()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 1, output: '', errorOutput: 'Class "Database\Seeders\PlanSeeder" not found', durationMs: 5);

        $this->actingAs($this->owner)
            ->put(route('preview-data.update', $this->project), ['with' => 'examples'])
            ->assertSessionHasErrors(['app' => 'Your app could not be filled with examples. Ask me to fix its example data.']);
        $this->put(route('preview-data.update', $this->project), ['with' => 'everything'])->assertSessionHasErrors('with');

        $this->actingAs(User::factory()->create())
            ->put(route('preview-data.update', $this->project), ['with' => 'empty'])
            ->assertForbidden();
        $this->assertCount(1, $this->driver->executed);
    }

    public function test_nothing_is_read_or_changed_while_the_app_does_not_run()
    {
        Preview::query()->update(['status' => 'stopped']);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('data', fn (Assert $page) => $page->where('data', null)));
        $this->put(route('preview-data.update', $this->project), ['with' => 'empty'])
            ->assertSessionHasErrors(['app' => 'Your app is not running. Start it and try again.']);
        $this->assertSame([], $this->driver->executed);
    }
}
