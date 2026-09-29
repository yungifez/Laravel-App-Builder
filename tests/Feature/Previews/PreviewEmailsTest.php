<?php

namespace Tests\Feature\Previews;

use App\Actions\Previews\ReadPreviewEmails;
use App\Actions\Previews\ReadPreviewLog;
use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use App\Workspaces\WorkspaceManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewEmailsTest extends TestCase
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
    }

    public function test_the_owner_reads_the_email_the_app_on_show_sent_newest_first()
    {
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);
        $this->driver->files["{$workspace->driver_id}:storage/logs/laravel.log"] = $this->logOf(function () {
            Log::channel('preview')->error('Something broke.');
            User::factory()->create(['email' => 'ada@example.test'])->notifyNow(new ResetPassword('secret-token'));
            Log::channel('preview')->info("A note\nover two lines.");
            Mail::mailer('log')->raw('Your table is booked.', fn ($message) => $message->to('grace@example.test')->subject('Réservation confirmée ☕'));
        });

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('emails')->reloadOnly('emails', fn (Assert $page) => $page
                ->count('emails', 2)
                ->where('emails.0.to', 'grace@example.test')
                ->where('emails.0.subject', 'Réservation confirmée ☕')
                ->where('emails.0.text', 'Your table is booked.')
                ->where('emails.0.html', null)
                ->where('emails.1.to', 'ada@example.test')
                ->where('emails.1.subject', 'Reset your password')
                ->where('emails.1.html', fn (string $html) => str_contains($html, '/reset-password/secret-token?email=ada%40example.test') && str_contains($html, '<html'))
                ->where('emails.1.text', fn (string $text) => str_contains($text, '/reset-password/secret-token') && ! str_contains($text, '<html'))
                ->where('emails.1.sent_at', fn (?string $time) => $time !== null)));
    }

    public function test_there_is_no_email_before_the_app_runs_or_sends_any()
    {
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        $preview = Preview::factory()->editable()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);
        $this->driver->files["{$workspace->driver_id}:storage/logs/laravel.log"] = $this->logOf(fn () => Mail::mailer('log')->raw('Hi', fn ($message) => $message->to('ada@example.test')->subject('Hi')));

        // Still starting.
        $this->actingAs($this->owner)->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('emails', fn (Assert $page) => $page->where('emails', [])));

        // Running, with no log written yet.
        $preview->update(['status' => 'ready']);
        unset($this->driver->files["{$workspace->driver_id}:storage/logs/laravel.log"]);

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('emails', fn (Assert $page) => $page->where('emails', [])));
    }

    public function test_log_polls_request_a_bounded_tail_and_share_the_cached_read()
    {
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        $preview = Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);
        $driver = Mockery::mock(FakeWorkspaceDriver::class);
        $driver->shouldReceive('readFile')->once()->with($workspace->driver_id, 'storage/logs/laravel.log', 2_000_000)->andReturn('recent log');
        app(WorkspaceManager::class)->extend('fake', fn () => $driver);

        $this->assertSame('recent log', app(ReadPreviewLog::class)->handle($preview));
        $this->assertSame('recent log', app(ReadPreviewLog::class)->handle($preview));
    }

    public function test_the_owner_deletes_one_email_or_all_of_them_with_a_mark_in_the_app_log()
    {
        $workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $workspace->id]);
        $log = "{$workspace->driver_id}:storage/logs/laravel.log";
        $this->driver->files[$log] = $this->logOf(function () {
            Mail::mailer('log')->raw('Welcome.', fn ($message) => $message->to('ada@example.test')->subject('Welcome'));
            Mail::mailer('log')->raw('Booked.', fn ($message) => $message->to('grace@example.test')->subject('Booked'));
        });
        // Run the command for real on a copy of the log, as the workspace would.
        $this->driver->onExec = function (string $workspaceId, array $command) use ($log) {
            $file = tempnam(sys_get_temp_dir(), 'log-');
            File::put($file, $this->driver->files[$log]);
            $result = Process::run([...array_slice($command, 0, 3), $file, ...array_slice($command, 4)]);
            $this->driver->files[$log] = File::get($file);
            File::delete($file);

            return new CommandResult(exitCode: $result->exitCode() ?? 1, output: '', errorOutput: $result->errorOutput(), durationMs: 5);
        };
        $emails = fn () => $this->get(route('projects.show', $this->project));
        $ids = array_column(app(ReadPreviewEmails::class)->handle($this->project), 'id');

        $this->actingAs($this->owner)->delete(route('preview-emails.destroy', $this->project), ['emails' => [$ids[0]]])->assertSessionHasNoErrors();

        $emails()->assertInertia(fn (Assert $page) => $page->reloadOnly('emails', fn (Assert $page) => $page
            ->count('emails', 1)
            ->where('emails.0.subject', 'Welcome')));
        $this->assertStringContainsString('Emails deleted: '.$ids[0], $this->driver->files[$log]);

        $this->delete(route('preview-emails.destroy', $this->project), ['emails' => $ids])->assertSessionHasNoErrors();

        $emails()->assertInertia(fn (Assert $page) => $page->reloadOnly('emails', fn (Assert $page) => $page->where('emails', [])));
    }

    public function test_only_people_who_can_change_the_app_delete_its_email()
    {
        $this->actingAs(User::factory()->create())
            ->delete(route('preview-emails.destroy', $this->project), ['emails' => [str_repeat('a', 40)]])
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->delete(route('preview-emails.destroy', $this->project), ['emails' => ['not-an-email']])
            ->assertSessionHasErrors('emails.0');

        // Nothing runs, so there is nothing to delete from.
        $this->delete(route('preview-emails.destroy', $this->project), ['emails' => [str_repeat('a', 40)]])
            ->assertSessionHasErrors('app');
    }

    public function test_only_people_who_can_see_the_app_read_its_email()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', $this->project))
            ->assertForbidden();
    }

    /**
     * Write what the callback logs and mails to a log file, as an app on
     * the log mailer does, and return it.
     */
    protected function logOf(callable $callback): string
    {
        $path = storage_path('framework/testing/preview-'.getmypid().'.log');
        File::delete($path);
        config([
            'logging.channels.preview' => ['driver' => 'single', 'path' => $path],
            'mail.mailers.log.channel' => 'preview',
            'mail.default' => 'log',
        ]);

        try {
            $callback();

            return File::get($path);
        } finally {
            File::delete($path);
        }
    }
}
