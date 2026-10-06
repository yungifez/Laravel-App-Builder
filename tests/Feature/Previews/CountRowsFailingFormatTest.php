<?php

namespace Tests\Feature\Previews;

use App\Actions\Previews\CountRowsFailingFormat;
use App\Actions\Projects\CreateProject;
use App\Enums\PreviewStatus;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

/**
 * Saved rows a stricter format would refuse are counted in the app on
 * show, through the app itself; only the count comes back (§9 Formats).
 */
class CountRowsFailingFormatTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected Project $project;

    protected Preview $preview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
        $this->preview = Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);
    }

    public function test_the_rows_the_stricter_rule_refuses_are_counted_in_the_app_on_show()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 0, output: "Some notice\n".json_encode(['rows' => 30, 'failing' => 12]), errorOutput: '', durationMs: 5);

        $counted = app(CountRowsFailingFormat::class)->handle($this->project, 'branches', 'phone', 'phone', ['CA', 'US']);

        $this->assertSame(['rows' => 30, 'failing' => 12], $counted);
        $command = $this->driver->executed[0]['command'];
        $this->assertSame(['php', '-r'], array_slice($command, 0, 2));
        // Only names and settings go in; the script is the same each time.
        $this->assertSame(['--', 'branches', 'phone', 'phone', 'CA,US'], array_slice($command, 3));
    }

    public function test_an_app_with_no_saved_values_counts_none()
    {
        $this->driver->onExec = fn () => new CommandResult(exitCode: 0, output: json_encode(['rows' => 0, 'failing' => 0]), errorOutput: '', durationMs: 5);

        $this->assertSame(['rows' => 0, 'failing' => 0], app(CountRowsFailingFormat::class)->handle($this->project, 'books', 'isbn', 'isbn', ['13']));
    }

    public function test_nothing_is_counted_when_the_app_is_not_running_or_cannot_be_read()
    {
        // The table is not there, or the script failed.
        $this->driver->onExec = fn () => new CommandResult(exitCode: 3, output: '', errorOutput: '', durationMs: 5);
        $this->assertNull(app(CountRowsFailingFormat::class)->handle($this->project, 'branches', 'phone', 'phone', ['CA']));

        $this->driver->onExec = fn () => new CommandResult(exitCode: 0, output: '{"rows":"many"}', errorOutput: '', durationMs: 5);
        $this->assertNull(app(CountRowsFailingFormat::class)->handle($this->project, 'branches', 'phone', 'phone', ['CA']));

        $this->preview->update(['status' => PreviewStatus::Stopped]);
        $executed = count($this->driver->executed);
        $this->assertNull(app(CountRowsFailingFormat::class)->handle($this->project, 'branches', 'phone', 'phone', ['CA']));
        $this->assertCount($executed, $this->driver->executed, 'a stopped app is not started to count');
    }
}
