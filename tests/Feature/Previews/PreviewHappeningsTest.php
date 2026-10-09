<?php

namespace Tests\Feature\Previews;

use App\Actions\Previews\ReadPreviewHappenings;
use App\Actions\Projects\CreateProject;
use App\Jobs\StartPreview;
use App\Models\Preview;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;
use Tests\Fakes\FakeWorkspaceDriver;
use Tests\TestCase;
use TraceRecorder\Recorder;

/**
 * What the app on show did behind each page, in plain words, and the
 * switch that makes one kind of thing fail while the owner tries it.
 */
class PreviewHappeningsTest extends TestCase
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
        config(['builder.preview.domain' => 'preview.test', 'builder.preview.public_port' => null, 'builder.preview.recorder.enabled' => true]);
        $this->owner = User::factory()->create();
        $this->project = app(CreateProject::class)->handle($this->owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
        app(ProjectRepository::class)->import($this->project);
        $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
        $this->preview = Preview::factory()->editable()->ready()->create(['project_id' => $this->project->id, 'workspace_id' => $this->workspace->id]);
    }

    /**
     * What the recorder inside the app wrote, one request a line.
     *
     * @param  list<array<string, mixed>>  $requests
     */
    protected function recorded(array $requests): void
    {
        $this->driver->files["{$this->workspace->driver_id}:storage/logs/recorder/trace.jsonl"] = implode("\n", array_map(fn (array $request) => json_encode($request), $requests))."\n";
    }

    public function test_the_owner_reads_what_the_app_did_behind_its_last_pages_in_plain_words()
    {
        $this->recorded([
            ['n' => 0, 'method' => 'GET', 'route' => '/bookings', 'status' => 200, 'effects' => [
                ['kind' => 'query', 'sql' => 'select * from "sessions" where "id" = ?'],
                ['kind' => 'query', 'sql' => 'select * from "bookings" inner join "rooms" on "rooms"."id" = "bookings"."room_id"'],
            ]],
            ['n' => 1, 'method' => 'POST', 'route' => '/bookings', 'status' => 302, 'effects' => [
                ['kind' => 'query', 'sql' => 'select * from "rooms" where "id" = ? limit 1'],
                ['kind' => 'begin'],
                ['kind' => 'query', 'sql' => 'insert into "bookings" ("room_id", "day") values (?, ?)'],
                ['kind' => 'query', 'sql' => 'update "rooms" set "booked" = ? where "id" = ?'],
                ['kind' => 'commit'],
                ['kind' => 'mail', 'what' => 'App\Mail\BookingConfirmedMail'],
                ['kind' => 'notification', 'what' => 'App\Notifications\RoomBookedNotification'],
                ['kind' => 'job', 'what' => 'App\Jobs\SendReminder', 'later' => true],
                ['kind' => 'http', 'what' => 'POST api.stripe.com'],
                ['kind' => 'file', 'what' => 'write'],
                ['kind' => 'cache', 'what' => 'write'],
            ]],
            ['n' => 2, 'method' => 'POST', 'route' => '/bookings/{booking}/cancel', 'status' => 500, 'fault' => 1, 'effects' => [
                ['kind' => 'query', 'sql' => 'delete from "bookings" where "id" = ?'],
                ['kind' => 'cache', 'what' => 'forget'],
                ['kind' => 'mail', 'what' => 'App\Mail\BookingCancelled', 'failed' => true],
                ['kind' => 'notification', 'what' => 'App\Notifications\BookingCancelledNotification', 'failed' => true],
                ['kind' => 'cache', 'what' => 'read', 'failed' => true],
                ['kind' => 'rollback'],
            ]],
        ]);
        $this->driver->files["{$this->workspace->driver_id}:storage/logs/recorder/fault.json"] = json_encode(['kind' => 'mail']);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->missing('happenings')->reloadOnly('happenings', fn (Assert $page) => $page
                ->where('happenings.fault', 'mail')
                ->where('happenings.requests', [
                    [
                        'id' => '3',
                        'page' => 'Sent a form on /bookings/{booking}/cancel',
                        'status' => 500,
                        'outcome' => 'Ended in an error. See Problems.',
                        'did' => [
                            ['text' => 'Deleted a booking', 'failed' => false],
                            ['text' => 'Forgot what it kept for later', 'failed' => false],
                            ['text' => 'Could not send the email: Booking cancelled', 'failed' => true],
                            ['text' => 'Could not leave the notice: Booking cancelled', 'failed' => true],
                            ['text' => 'Could not read what it kept for later', 'failed' => true],
                            ['text' => 'Put the save back', 'failed' => false],
                        ],
                        'times' => 1,
                    ],
                    [
                        'id' => '2',
                        'page' => 'Sent a form on /bookings',
                        'status' => 302,
                        'outcome' => 'Sent the visitor on to another page',
                        'did' => [
                            ['text' => 'Saved a new booking', 'failed' => false],
                            ['text' => 'Changed a room', 'failed' => false],
                            ['text' => 'Sent an email: Booking confirmed', 'failed' => false],
                            ['text' => 'Left a notice: Room booked', 'failed' => false],
                            ['text' => 'Put a task in the background for later: Send reminder', 'failed' => false],
                            ['text' => 'Asked api.stripe.com', 'failed' => false],
                            ['text' => 'Stored a file', 'failed' => false],
                            ['text' => 'Kept something for later', 'failed' => false],
                            // A save is not a look; only the room read before it is.
                            ['text' => 'Looked at rooms', 'failed' => false],
                        ],
                        'times' => 1,
                    ],
                    [
                        'id' => '1',
                        'page' => 'Opened /bookings',
                        'status' => 200,
                        'outcome' => null,
                        // What the framework keeps for itself is not something the app did.
                        'did' => [['text' => 'Looked at bookings, rooms', 'failed' => false]],
                        'times' => 1,
                    ],
                ])));
    }

    public function test_the_same_page_doing_the_same_again_is_counted_not_listed_again()
    {
        $opened = ['method' => 'GET', 'route' => '/about', 'status' => 200, 'effects' => [['kind' => 'query', 'sql' => 'select * from "users"']]];

        $this->recorded([
            ['n' => 0, ...$opened],
            ['n' => 1, ...$opened],
            ['n' => 2, ...$opened, 'status' => 500],
            ['n' => 3, ...$opened],
            ['n' => 4, ...$opened],
            ['n' => 5, ...$opened],
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page
                // An error between them keeps its own row, in its place.
                ->count('happenings.requests', 3)
                ->where('happenings.requests.0.id', '6')
                ->where('happenings.requests.0.times', 3)
                ->where('happenings.requests.1.status', 500)
                ->where('happenings.requests.1.times', 1)
                ->where('happenings.requests.2.id', '2')
                ->where('happenings.requests.2.times', 2)));
    }

    public function test_our_own_check_that_the_app_started_is_not_shown_as_something_it_did()
    {
        $this->recorded([
            ['n' => 0, 'method' => 'GET', 'route' => '/up', 'status' => 200, 'effects' => []],
            ['n' => 1, 'method' => 'GET', 'route' => '/about', 'status' => 200, 'effects' => []],
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page
                ->count('happenings.requests', 1)
                ->where('happenings.requests.0.page', 'Opened /about')));
    }

    public function test_a_save_is_named_by_the_table_it_writes_not_the_columns_an_upsert_names()
    {
        $this->recorded([
            ['n' => 0, 'method' => 'GET', 'route' => '/register', 'status' => 200, 'effects' => [
                ['kind' => 'query', 'sql' => 'insert into `cache` (`expiration`, `key`, `value`) values (?, ?, ?) on duplicate key update `expiration` = values(`expiration`), `key` = values(`key`), `value` = values(`value`)'],
                ['kind' => 'query', 'sql' => 'insert into "likes" ("user_id", "count") values (?, ?) on conflict ("user_id") do update set "count" = "excluded"."count"'],
            ]],
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page
                ->where('happenings.requests.0.did', [['text' => 'Saved a new like', 'failed' => false]])));
    }

    public function test_a_part_of_a_page_that_updates_itself_is_named_by_that_part()
    {
        $this->recorded([
            ['n' => 0, 'method' => 'POST', 'route' => '/livewire-7a0bd264/update#about-users-avatars@__lazyLoad', 'status' => 200, 'effects' => []],
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page
                ->where('happenings.requests.0.page', 'Updated the about users avatars part of a page')));
    }

    public function test_a_task_the_app_ran_on_its_own_is_named_in_words()
    {
        $this->recorded([
            ['n' => 0, 'method' => 'ARTISAN', 'route' => 'delete:non-email-verified-users', 'status' => 200, 'effects' => []],
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page
                ->where('happenings.requests.0.page', 'Ran a task: Delete non email verified users')));
    }

    public function test_the_newest_form_the_recorder_kept_can_be_sent_twice()
    {
        $this->recorded([
            ['n' => 0, 'method' => 'POST', 'route' => '/books', 'status' => 302, 'effects' => []],
            ['n' => 1, 'method' => 'GET', 'route' => '/books', 'status' => 200, 'effects' => []],
            ['n' => 2, 'method' => 'POST', 'route' => '/books', 'status' => 302, 'effects' => [['kind' => 'query', 'sql' => 'insert into "books" ("title") values (?)']]],
            ['n' => 3, 'method' => 'POST', 'route' => '/shelves', 'status' => 302, 'effects' => []],
        ]);
        $this->driver->files["{$this->workspace->driver_id}:storage/logs/recorder/last-send.json"] = json_encode(['method' => 'POST', 'path' => '/books', 'route' => '/books']);

        // Newest first: the shelf, then the second book send, which is the one kept.
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page
                ->where('happenings.requests.1.page', 'Sent a form on /books')
                ->where('happenings.requests.1.id', '3')
                ->where('happenings.again', '3')));
    }

    public function test_no_form_can_be_sent_twice_when_none_was_kept()
    {
        $this->recorded([
            ['n' => 0, 'method' => 'POST', 'route' => '/books', 'status' => 302, 'effects' => []],
        ]);

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page->where('happenings.again', null)));
    }

    public function test_an_app_that_did_nothing_yet_or_does_not_run_has_nothing_to_show()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page->where('happenings', ['fault' => 'none', 'again' => null, 'requests' => []])));

        $this->preview->delete();

        $this->get(route('projects.show', $this->project))
            ->assertInertia(fn (Assert $page) => $page->reloadOnly('happenings', fn (Assert $page) => $page->where('happenings', ['fault' => 'none', 'again' => null, 'requests' => []])));
    }

    public function test_the_owner_makes_the_apps_email_fail_and_lets_it_work_again()
    {
        $this->actingAs($this->owner)
            ->put(route('preview-fault.update', $this->project), ['fault' => 'mail'])
            ->assertSessionHasNoErrors();
        $this->assertSame('{"kind":"mail"}', $this->driver->files["{$this->workspace->driver_id}:storage/logs/recorder/fault.json"]);

        $this->put(route('preview-fault.update', $this->project), ['fault' => 'none'])->assertSessionHasNoErrors();
        $this->assertSame('{"kind":null}', $this->driver->files["{$this->workspace->driver_id}:storage/logs/recorder/fault.json"]);

        // Every kind the owner can pick is one the recorder can make fail, and nothing else is.
        class_exists(Recorder::class) || require_once resource_path('trace-recorder/src/Recorder.php');
        foreach (array_keys(ReadPreviewHappenings::FAULTS) as $kind) {
            $this->assertContains($kind, Recorder::LIVE);
            $this->put(route('preview-fault.update', $this->project), ['fault' => $kind])->assertSessionHasNoErrors();
        }
        $this->put(route('preview-fault.update', $this->project), ['fault' => 'query'])->assertSessionHasErrors('fault');

        // Not for someone else's app, and not while the app does not run.
        $this->actingAs(User::factory()->create())
            ->put(route('preview-fault.update', $this->project), ['fault' => 'mail'])
            ->assertForbidden();
        $this->preview->delete();
        $this->actingAs($this->owner)
            ->put(route('preview-fault.update', $this->project), ['fault' => 'mail'])
            ->assertSessionHasErrors('fault');
    }

    public function test_the_apps_server_loads_the_recorder_and_starts_with_all_working()
    {
        config(['builder.preview.recorder.prepend' => '/opt/trace-recorder/prepend.php']);
        $job = new StartPreview($this->preview);
        $command = (new \ReflectionMethod($job, 'serverCommand'))->invoke($job, 20001, '127.0.0.1');

        $shell = $command[array_search('-c', $command, true) + 1];
        // The folder is made, a fault or a clock from before is dropped, and the recorder records into it.
        $this->assertStringContainsString('mkdir -p "$1" && rm -f "$1/fault.json" "$1/clock.json" && export TRACE_RECORDER_DIR="$PWD/$1"', $shell);
        $this->assertStringContainsString("[ -f '/opt/trace-recorder/prepend.php' ]", $shell);
        $this->assertContains('storage/logs/recorder', $command);
        $this->assertContains('auto_prepend_file=/opt/trace-recorder/prepend.php', $command);
        $this->assertSame(['php', '-d', 'auto_prepend_file=/opt/trace-recorder/prepend.php', '-S'], array_slice($command, array_search('php', $command, true), 4));

        // Off, the server is as before.
        config(['builder.preview.recorder.enabled' => false]);
        $command = (new \ReflectionMethod($job, 'serverCommand'))->invoke($job, 20001, '127.0.0.1');
        $this->assertContains('cd public && exec "$@"', $command);
        $this->assertNotContains('-d', $command);
    }
}
