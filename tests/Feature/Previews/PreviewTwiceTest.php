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
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewTwiceTest extends TestCase
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
        config(['builder.preview.listen_host' => '0.0.0.0']);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        $this->preview = Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id, 'port' => 20004]);
    }

    /**
     * The app's answer to both sends, and what the recorder wrote for each.
     *
     * @param  list<int>  $statuses
     * @param  list<list<array<string, mixed>>>  $effects
     */
    protected function answers(array $statuses, array $effects, int $exit = 0): void
    {
        $sends = array_map(fn (int $status, array $did) => ['method' => 'POST', 'route' => '/books', 'status' => $status, 'effects' => $did], $statuses, $effects);

        $this->driver->onExec = fn () => new CommandResult(exitCode: $exit, output: "A notice\n".json_encode(['statuses' => $statuses, 'sends' => $sends]), errorOutput: '', durationMs: 5);
    }

    public function test_the_last_form_sent_twice_at_once_that_saves_twice_says_so()
    {
        $saved = [['kind' => 'query', 'sql' => 'insert into "books" ("title") values (?)']];
        $this->answers([302, 302], [$saved, $saved]);

        $this->actingAs($this->owner)
            ->postJson(route('preview-twice.store', $this->project))
            ->assertOk()
            ->assertJsonPath('words', 'Both went through, so it was done twice: saved a new book, two times.')
            ->assertJsonPath('broke', false)
            ->assertJsonPath('sends.0.did.0.text', 'Saved a new book')
            ->assertJsonPath('sends.1.status', 302);

        // Only the recorder's folder and the app's own address reach the app, as arguments.
        $command = $this->driver->executed[0]['command'];
        $this->assertSame(['php', '-r'], array_slice($command, 0, 2));
        $this->assertSame(['--', 'storage/logs/recorder', '127.0.0.1:20004'], array_slice($command, 3));
    }

    public function test_a_second_send_the_app_turns_away_or_saves_nothing_for_is_told_apart()
    {
        $this->answers([302, 302], [[], []]);
        $this->actingAs($this->owner)
            ->postJson(route('preview-twice.store', $this->project))
            ->assertJsonPath('words', 'Both went through, and nothing was saved twice.');

        $this->answers([302, 419], [[['kind' => 'query', 'sql' => 'insert into "books" ("title") values (?)']], []]);
        $this->actingAs($this->owner)
            ->postJson(route('preview-twice.store', $this->project))
            ->assertJsonPath('words', 'One went through, and your app turned the other away.')
            ->assertJsonPath('broke', false);
    }

    public function test_a_send_that_breaks_or_no_form_to_send_is_explained()
    {
        $this->answers([302, 500], [[], [['kind' => 'query', 'sql' => 'insert into "books" ("title") values (?)', 'failed' => true]]]);
        $this->actingAs($this->owner)
            ->postJson(route('preview-twice.store', $this->project))
            ->assertJsonPath('words', 'One of them broke, so that person saw an error page. See Problems for why.')
            ->assertJsonPath('broke', true);

        $this->driver->onExec = fn () => new CommandResult(exitCode: 4, output: '', errorOutput: '', durationMs: 5);
        $this->actingAs($this->owner)
            ->postJson(route('preview-twice.store', $this->project))
            ->assertJsonValidationErrors(['app' => 'Send a form in your app first']);

        $this->driver->onExec = fn () => new CommandResult(exitCode: 255, output: 'PHP Fatal error', errorOutput: '', durationMs: 5);
        $this->actingAs($this->owner)
            ->postJson(route('preview-twice.store', $this->project))
            ->assertJsonValidationErrors(['app' => 'This is our fault.']);
    }

    public function test_only_people_who_can_change_the_app_send_twice_and_only_while_it_runs()
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('preview-twice.store', $this->project))
            ->assertForbidden();

        $this->preview->update(['status' => 'stopped']);
        $this->actingAs($this->owner)
            ->postJson(route('preview-twice.store', $this->project))
            ->assertJsonValidationErrors(['app' => 'Your app is not running.']);
        $this->assertSame([], $this->driver->executed);
    }
}
