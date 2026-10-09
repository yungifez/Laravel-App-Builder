<?php

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;

uses(FakesWorkspaces::class, PreparesRuns::class);

/*
| What the app did behind its last pages, in plain words, in a real browser.
| And the owner makes email fail from the same place, to see what a visitor
| would see.
*/

it('says what the app did behind each page and lets the owner make email fail', function () {
    $driver = $this->fakeWorkspaces();
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    Preview::factory()->editable()->ready()->create(['project_id' => $project->id, 'workspace_id' => $workspace->id]);

    $recorder = "{$workspace->driver_id}:storage/logs/recorder";
    $driver->files["{$recorder}/trace.jsonl"] = implode("\n", array_map(fn (array $request) => json_encode($request), [
        ['test' => null, 'n' => 0, 'method' => 'GET', 'route' => '/bookings', 'status' => 200, 'refused' => false, 'effects' => [
            ['kind' => 'query', 'sql' => 'select * from "bookings" inner join "rooms" on "rooms"."id" = "bookings"."room_id"'],
        ], 'blind' => []],
        ['test' => null, 'n' => 1, 'method' => 'POST', 'route' => '/bookings', 'status' => 302, 'refused' => false, 'effects' => [
            ['kind' => 'begin'],
            ['kind' => 'query', 'sql' => 'insert into "bookings" ("room_id") values (?)'],
            ['kind' => 'mail', 'what' => 'App\Mail\BookingConfirmedMail'],
            ['kind' => 'commit'],
        ], 'blind' => []],
    ]))."\n";

    $this->actingAs($owner);

    $page = visit(route('projects.show', $project))
        ->click('@showing-happened')
        // Newest first: the form that was sent, then the page that was opened.
        ->assertSeeIn('@app-happening-2', 'Sent a form on /bookings')
        ->assertSeeIn('@app-happening-2', 'Sent the visitor on to another page')
        ->assertSeeIn('@app-happening-2', 'Saved a new booking')
        ->assertSeeIn('@app-happening-2', 'Sent an email: Booking confirmed')
        ->assertSeeIn('@app-happening-1', 'Opened /bookings')
        ->assertSeeIn('@app-happening-1', 'Looked at bookings, rooms')
        ->assertMissing('@app-fault-on');

    $page->select('@app-fault', 'mail')
        ->assertSee('Email is down. Try your app and see what a visitor sees.')
        ->assertSeeIn('@app-fault-on', 'until you choose "All works" or it starts again');

    // The app on show reads this file as each page starts.
    expect($driver->files["{$recorder}/fault.json"] ?? null)->toBe('{"kind":"mail"}');

    $page->select('@app-fault', 'none')
        ->assertSee('Your app works as usual again')
        ->assertMissing('@app-fault-on')
        ->assertNoJavaScriptErrors();

    expect($driver->files["{$recorder}/fault.json"])->toBe('{"kind":null}');
});
