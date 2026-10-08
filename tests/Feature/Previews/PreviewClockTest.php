<?php

namespace Tests\Feature\Previews;

use App\Actions\Previews\ReadPreviewClock;
use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewClockTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    protected Preview $preview;

    protected const CLOCK = 'storage/logs/recorder/clock.json';

    /** Whether the app can list its schedule. */
    protected bool $lists = true;

    /** @var list<array{task: string, ahead: int}> Each task run, with how far ahead the clock was then */
    protected array $runs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday, at noon.
        $this->travelTo('2026-10-05 12:00:00');
        $this->driver = $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        $this->preview = Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);

        $tasks = [
            $this->task('php artisan reminders:send-daily', '0 8 * * *'),
            $this->task('php artisan invoices:check', '* * * * *'),
            $this->task('php artisan report:weekly', '0 9 * * 1', 'Send the weekly report'),
        ];
        $this->driver->onExec = function (string $workspace, array $command) use ($tasks) {
            if (in_array('schedule:list', $command, true)) {
                return new CommandResult(exitCode: $this->lists ? 0 : 1, output: $this->lists ? json_encode($tasks) : 'Class "App\Report" not found', errorOutput: '', durationMs: 5);
            }

            if (in_array('schedule:test', $command, true)) {
                $this->runs[] = ['task' => substr((string) end($command) === '--no-interaction' ? $command[count($command) - 2] : '', 7), 'ahead' => json_decode($this->driver->files["{$workspace}:".self::CLOCK], true)['ahead']];
            }

            return new CommandResult(exitCode: 0, output: 'ok', errorOutput: '', durationMs: 5);
        };
    }

    public function test_jumping_a_week_runs_what_the_schedule_would_have_run_in_it_and_keeps_the_app_there()
    {
        $this->actingAs($this->owner)
            ->put(route('preview-clock.update', $this->project), ['jump' => 'week'])
            ->assertSessionHasNoErrors();

        // The daily reminder ran each of the 7 mornings, oldest first, each at its own time:
        // the first at 08:00 the next day, 20 hours on.
        $reminders = array_values(array_filter($this->runs, fn (array $run) => $run['task'] === 'reminders:send-daily'));
        $this->assertCount(7, $reminders);
        $this->assertSame(20 * 3600, $reminders[0]['ahead']);
        $this->assertSame(20 * 3600 + 6 * 86400, $reminders[6]['ahead']);
        // The task that runs every minute ran only its last 7 minutes, and the weekly one once.
        $this->assertSame(7, collect($this->runs)->where('task', 'invoices:check')->count());
        $this->assertSame(7 * 86400 - 6 * 60, collect($this->runs)->firstWhere('task', 'invoices:check')['ahead']);
        $this->assertSame([21 * 3600 + 6 * 86400], collect($this->runs)->where('task', 'report:weekly')->pluck('ahead')->all());
        $this->assertSame(array_values(collect($this->runs)->sortBy('ahead')->all()), $this->runs);

        // The app stays a week ahead, and whoever was signed in stays signed in.
        $this->assertSame(['ahead' => 7 * 86400], json_decode($this->driver->files["{$this->preview->workspace->driver_id}:".self::CLOCK], true));
        $this->assertSame(['php', 'storage/logs/recorder/sessions.php', (string) (7 * 86400)], collect($this->driver->executed)->last()['command']);

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('clock', fn (Assert $page) => $page
                ->where('clock.ahead', 7 * 86400)
                ->where('clock.now', '2026-10-12T12:00:00+00:00')
                ->where('clock.moving', false)
                ->where('clock.error', null)
                ->where('clock.ran', [
                    ['words' => 'Reminders send daily', 'times' => 7, 'failed' => 0],
                    ['words' => 'Send the weekly report', 'times' => 1, 'failed' => 0],
                    ['words' => 'Invoices check', 'times' => 7, 'failed' => 0],
                ])));
    }

    public function test_back_to_today_takes_at_once_and_runs_nothing()
    {
        $this->driver->files["{$this->preview->workspace->driver_id}:".self::CLOCK] = json_encode(['ahead' => 86400]);

        $this->actingAs($this->owner)
            ->put(route('preview-clock.update', $this->project), ['jump' => 'today'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['ahead' => 0], json_decode($this->driver->files["{$this->preview->workspace->driver_id}:".self::CLOCK], true));
        $this->assertSame([], $this->driver->executed);
    }

    public function test_an_app_that_cannot_list_its_schedule_stays_where_it_was_and_the_owner_is_told_why()
    {
        $this->lists = false;

        $this->actingAs($this->owner)
            ->put(route('preview-clock.update', $this->project), ['jump' => 'month'])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->runs);
        $this->assertArrayNotHasKey("{$this->preview->workspace->driver_id}:".self::CLOCK, $this->driver->files);
        $this->assertSame('Your app could not say what it runs on its own. See Problems for what went wrong.', Cache::get(ReadPreviewClock::outcomeKey($this->preview))['error']);
    }

    public function test_one_jump_at_a_time_only_the_owner_jumps_and_only_by_known_steps()
    {
        Cache::put(ReadPreviewClock::movingKey($this->preview), true);

        $this->actingAs($this->owner)
            ->put(route('preview-clock.update', $this->project), ['jump' => 'day'])
            ->assertSessionHasErrors(['jump' => 'Your app is still moving ahead. Wait for it to finish.']);

        $this->put(route('preview-clock.update', $this->project), ['jump' => 'year'])->assertSessionHasErrors('jump');

        $this->actingAs(User::factory()->create())
            ->put(route('preview-clock.update', $this->project), ['jump' => 'day'])
            ->assertForbidden();

        $this->assertSame([], $this->driver->executed);
    }

    /**
     * @return array<string, mixed>
     */
    protected function task(string $command, string $expression, ?string $description = null): array
    {
        return [
            'expression' => $expression,
            'command' => $command,
            'description' => $description,
            'next_due_date' => '2026-10-06 08:00:00 +00:00',
            'next_due_date_human' => '20 hours from now',
            'timezone' => 'UTC',
            'has_mutex' => false,
            'repeat_seconds' => null,
            'environments' => [],
        ];
    }
}
