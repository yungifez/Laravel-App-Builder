<?php

use App\Actions\Projects\CreateProject;
use App\Models\Preview;
use App\Models\User;
use App\Models\Workspace;
use App\Projects\ProjectRepository;
use App\Workspaces\CommandResult;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\PreparesRuns;

uses(FakesWorkspaces::class, PreparesRuns::class);

/*
| What the app told people, in a real browser: the email it sent and the
| notices it left for people inside the app, in one list beside the app.
*/

it('lists the email and the notices the app sent together, newest first', function () {
    $driver = $this->fakeWorkspaces();
    $owner = User::factory()->create();
    $project = app(CreateProject::class)->handle($owner, 'Acme', $this->makeProjectSource([]), draftNotes: false);
    app(ProjectRepository::class)->import($project);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    Preview::factory()->editable()->ready()->create(['project_id' => $project->id, 'workspace_id' => $workspace->id]);

    $driver->files["{$workspace->driver_id}:storage/logs/laravel.log"] = implode("\n", [
        '[2026-10-02 09:00:00] local.DEBUG: From: Acme <hello@example.test>',
        'To: grace@example.test',
        'Subject: Your table is booked',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=utf-8',
        '',
        'See you at eight.',
        '',
    ]);
    // The app answers with its notices when it is asked for them, and with nothing for the other tools.
    $driver->onExec = fn (string $workspaceId, array $command) => new CommandResult(
        exitCode: 0,
        output: str_contains($command[2] ?? '', 'DatabaseNotification') ? (string) json_encode([[
            'id' => '0b2c1f6e-1111-4222-8333-444455556666',
            'type' => 'App\Notifications\OrderShippedNotification',
            'data' => ['message' => 'Your order is on its way.', 'order_id' => 12],
            'read' => false,
            'sent_at' => '2026-10-02T10:00:00+00:00',
            'name' => 'Ada',
            'email' => 'ada@example.test',
        ]]) : '',
        errorOutput: '',
        durationMs: 5,
    );

    $this->actingAs($owner);

    visit(route('projects.show', $project))
        ->click('@showing-emails')
        ->assertSeeIn('@app-emails', '2 messages')
        // The notice is newer, so it is first and open.
        ->assertSeeIn('@app-email', 'Order shipped')
        ->assertSeeIn('@app-email-in-app', 'To Ada · inside your app · not opened yet')
        ->assertSeeIn('@app-email-body', 'Your order is on its way.')
        ->assertSeeIn('@app-emails', 'Your table is booked')
        ->assertNoJavaScriptErrors();
});
