<?php

namespace Tests\Feature\Previews;

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Previews\LoggedEmails;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;

class PreviewNoticesTest extends TestCase
{
    use FakesWorkspaces, PreparesRuns, RefreshDatabase;

    protected FakeWorkspaceDriver $driver;

    protected User $owner;

    protected Project $project;

    protected Workspace $workspace;

    protected Preview $preview;

    protected function setUp(): void
    {
        parent::setUp();

        $this->driver = $this->fakeWorkspaces();
        config(['builder.preview.domain' => 'preview.test', 'builder.preview.public_port' => null]);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        $this->preview = Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $this->workspace->id]);
    }

    /**
     * What the app prints when it is asked for its notices.
     *
     * @param  list<array<string, mixed>>  $notices
     */
    protected function appAnswers(array $notices): void
    {
        $this->driver->onExec = fn (string $workspaceId, array $command) => new CommandResult(
            exitCode: 0,
            output: "Some line the app printed\n".json_encode($notices),
            errorOutput: '',
            durationMs: 5,
        );
    }

    public function test_the_owner_reads_the_notices_the_app_on_show_left_for_people_inside_the_app()
    {
        $this->appAnswers([
            [
                'id' => '0b2c1f6e-1111-4222-8333-444455556666',
                'type' => 'App\Notifications\OrderShippedNotification',
                'data' => ['message' => 'Your order is on its way.', 'order_id' => 12, 'url' => '/orders/12', 'gift' => true, 'api_token' => 'never-shown', 'items' => ['a', 'b']],
                'read' => false,
                'sent_at' => '2026-10-02T10:00:00+00:00',
                'name' => 'Ada',
                'email' => 'ada@example.test',
            ],
            [
                'id' => '7',
                'type' => 'App\Notifications\Welcome',
                'data' => ['title' => 'Welcome to Acme', 'body' => 'Glad you are here.'],
                'read' => true,
                'sent_at' => '2026-10-02T09:00:00+00:00',
                'name' => null,
                'email' => null,
            ],
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('notices')->reloadOnly('notices', fn (Assert $page) => $page
                ->where('notices', [
                    [
                        'id' => sha1("notice\n0b2c1f6e-1111-4222-8333-444455556666"),
                        'sent_at' => '2026-10-02T10:00:00+00:00',
                        'to' => 'Ada',
                        // A notice with no title is called by its kind, in words.
                        'subject' => 'Order shipped',
                        // A path in the app is its whole address, and what is secret or not one value is left out.
                        'text' => "Your order is on its way.\n\nOrder id: 12\nLink: ".rtrim($this->preview->url(), '/')."/orders/12\nGift: yes",
                        'read' => false,
                    ],
                    [
                        'id' => sha1("notice\n7"),
                        'sent_at' => '2026-10-02T09:00:00+00:00',
                        'to' => 'Someone',
                        'subject' => 'Welcome to Acme',
                        'text' => 'Glad you are here.',
                        'read' => true,
                    ],
                ])));

        // Only the limit reaches the app, as an argument: the code run is always the same.
        $command = $this->driver->executed[0]['command'];
        $this->assertSame(['php', '-r'], array_slice($command, 0, 2));
        $this->assertStringContainsString('Illuminate\Notifications\DatabaseNotification', $command[2]);
        $this->assertSame(['--', '50'], array_slice($command, 3));
    }

    public function test_a_notice_the_owner_deleted_from_the_list_stays_out_of_it()
    {
        $this->appAnswers([
            ['id' => '1', 'type' => 'App\Notifications\Welcome', 'data' => [], 'read' => false, 'sent_at' => null, 'name' => 'Ada', 'email' => null],
            ['id' => '2', 'type' => 'App\Notifications\Welcome', 'data' => [], 'read' => false, 'sent_at' => null, 'name' => 'Grace', 'email' => null],
        ]);
        // The mark an email gets when the owner deletes it works for a notice too.
        $this->driver->files["{$this->workspace->driver_id}:storage/logs/laravel.log"] = LoggedEmails::deletion([sha1("notice\n1")], now());

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('notices', fn (Assert $page) => $page
                ->count('notices', 1)
                ->where('notices.0.to', 'Grace')
                ->where('notices.0.subject', 'Welcome')
                ->where('notices.0.text', '')));
    }

    public function test_an_app_that_keeps_no_notices_or_does_not_run_has_none()
    {
        // The app has no table for notices: it ends with a code of its own.
        $this->driver->onExec = fn () => new CommandResult(exitCode: 3, output: '', errorOutput: '', durationMs: 5);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('notices', fn (Assert $page) => $page->where('notices', [])));

        $this->preview->delete();
        $this->driver->executed = [];

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('notices', fn (Assert $page) => $page->where('notices', [])));
        $this->assertSame([], $this->driver->executed);
    }

    public function test_the_owner_deletes_every_email_and_notice_the_list_can_hold_at_once()
    {
        $ids = array_map(fn (int $n) => sha1("message {$n}"), range(1, 100));

        $this->actingAs($this->owner)
            ->delete(route('preview-emails.destroy', $this->project), ['emails' => $ids])
            ->assertSessionHasNoErrors();
        $this->delete(route('preview-emails.destroy', $this->project), ['emails' => [...$ids, sha1('one more')]])
            ->assertSessionHasErrors('emails');
    }
}
