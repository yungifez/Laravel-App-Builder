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

class PreviewScheduleTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    protected string $ran = 'Running [Callback] ... DONE';

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);

        $tasks = [
            $this->task('php artisan reminders:send-daily', '0 8 * * *'),
            $this->task('php artisan reminders:send-daily', '30 17 * * *'),
            $this->task('Tidy old bookings', '*/15 * * * *', 'Tidy old bookings'),
            $this->task('Closure at: routes/console.php:12', '0 9 * * 1'),
            $this->task('php artisan invoices:check', '* * * * *', repeat: 10),
            $this->task('php artisan report', '5 4 1,15 * *'),
        ];
        $this->driver->onExec = fn (string $workspace, array $command) => new CommandResult(
            exitCode: 0,
            output: $command[2] === 'schedule:list' ? "A notice\n".json_encode($tasks) : $this->ran,
            errorOutput: '',
            durationMs: 5,
        );
    }

    public function test_the_owner_sees_what_their_app_does_on_its_own_and_when()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('schedule')->reloadOnly('schedule', fn (Assert $page) => $page
                ->count('schedule', 5)
                ->where('schedule.0.words', 'Reminders send daily')
                ->where('schedule.0.name', 'reminders:send-daily')
                ->where('schedule.0.when', 'Every day at 08:00 UTC')
                ->where('schedule.0.next', '2026-10-01T08:00:00+00:00')
                ->where('schedule.1.words', 'Tidy old bookings')
                ->where('schedule.1.when', 'Every 15 minutes')
                ->where('schedule.2.words', 'A task without a name')
                ->where('schedule.2.name', 'Closure')
                ->where('schedule.2.when', 'Every Monday at 09:00 UTC')
                ->where('schedule.3.when', 'Every 10 seconds')
                ->where('schedule.4.when', 'On its own timetable')));
    }

    public function test_the_owner_runs_a_task_now()
    {
        $this->actingAs($this->owner)
            ->post(route('preview-schedule-runs.store', $this->project), ['task' => 'reminders:send-daily'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['php', 'artisan', 'schedule:test', '--name=reminders:send-daily', '--no-interaction'], collect($this->driver->executed)->last()['command']);
        $this->assertSame('log', collect($this->driver->environments)->last()['MAIL_MAILER']);
    }

    public function test_only_a_task_in_the_app_is_run_and_a_failure_is_told_in_plain_words()
    {
        $this->actingAs($this->owner)
            ->post(route('preview-schedule-runs.store', $this->project), ['task' => 'db:wipe'])
            ->assertSessionHasErrors(['task' => 'This task is no longer in your app.']);

        $this->ran = 'No matching scheduled command found.';
        $this->post(route('preview-schedule-runs.store', $this->project), ['task' => 'Closure'])
            ->assertSessionHasErrors(['task' => 'Give this task a name in the app to run it from here.']);

        $this->actingAs(User::factory()->create())
            ->post(route('preview-schedule-runs.store', $this->project), ['task' => 'reminders:send-daily'])
            ->assertForbidden();

        $this->assertNull(collect($this->driver->executed)->first(fn (array $run) => ($run['command'][3] ?? null) === '--name=db:wipe'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function task(string $command, string $expression, ?string $description = null, ?int $repeat = null): array
    {
        return [
            'expression' => $expression,
            'command' => $command,
            'description' => $description,
            'next_due_date' => '2026-10-01 08:00:00 +00:00',
            'next_due_date_human' => '3 days from now',
            'timezone' => 'UTC',
            'has_mutex' => false,
            'repeat_seconds' => $repeat,
            'environments' => [],
        ];
    }
}
