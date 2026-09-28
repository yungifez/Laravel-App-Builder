<?php

namespace Tests\Feature\Previews;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
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
