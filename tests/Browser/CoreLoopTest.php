<?php

use App\Actions\Projects\CreateProject;
use App\Models\Project;
use App\Models\User;
use Tests\Concerns\BuildsInLocalWorkspaces;
use Tests\Concerns\FakesWorkspaces;
use Tests\Concerns\UsesAcceptanceSuite;
use Tests\Concerns\UsesReferenceSolutions;

uses(BuildsInLocalWorkspaces::class, FakesWorkspaces::class, UsesAcceptanceSuite::class, UsesReferenceSolutions::class);

/*
| The owner's core loop, in a real browser: ask for a change, read what was
| done at each level of detail, ask for more in the same chat, then keep or
| undo it. Changes are built from reference solutions, and their checks run
| in a fake workspace, so no model or container is involved.
*/

beforeEach(function () {
    $this->buildInLocalWorkspaces();
    $this->fakeWorkspaces();
    $this->useAcceptanceSuite();

    config([
        'builder.verification.workspace_driver' => 'fake',
        'builder.verification.setup' => [],
        'builder.verification.checks' => [],
    ]);

    $this->owner = User::factory()->create();
    $this->project = app(CreateProject::class)->handle(
        $this->owner, 'Acme', $this->useReferenceSolutions().'/source', draftNotes: false,
    );
});

function askForInvitations(Project $project)
{
    return visit(route('projects.show', $project))
        ->assertSee('Open my app')
        ->fill('prompt', 'Let owners and admins invite people by email.')
        ->click('@request-feature-button')
        ->assertSee('Owners and admins can invite people.');
}

it('builds a change the owner asks for, keeps it, then undoes it', function () {
    $this->actingAs($this->owner);

    $page = askForInvitations($this->project)
        ->click('@accept-change-button')
        ->assertVisible('@revert-change-button');

    $change = $this->project->featureRequests()->sole();
    expect($change->commit_sha)->not->toBeNull();

    $page->click('@revert-change-button')
        ->assertMissing('@revert-change-button')
        ->assertNoJavaScriptErrors();

    expect($change->refresh()->reverted_at)->not->toBeNull();
});

it('shows each level of detail on its own', function () {
    $this->actingAs($this->owner);

    $page = askForInvitations($this->project);

    $page->click('@detail-2')
        ->assertPresent('@detail-why')
        ->assertMissing('@detail-how')
        ->click('@detail-3')
        ->assertPresent('@detail-how')
        ->assertMissing('@detail-why')
        ->assertSee('TeamPolicy.php')
        ->click('@detail-4')
        ->assertPresent('@detail-code')
        ->assertMissing('@detail-how')
        ->assertNoJavaScriptErrors();
});

it('continues the open chat when the owner asks for more', function () {
    $this->actingAs($this->owner);

    askForInvitations($this->project)
        ->assertSeeIn('@composer-hint', 'Continues this chat')
        ->fill('prompt', 'Remind people who have not answered their invitation.')
        ->click('@request-feature-button')
        ->assertSee('People are reminded of invitations they have not answered.')
        ->assertSeeIn('@thread-earlier', 'Owners and admins can invite people.')
        ->assertNoJavaScriptErrors();

    $followUp = $this->project->featureRequests()->whereNotNull('parent_id')->sole();
    expect($followUp->solution_key)->toBe('invitation-reminders');
});

it('sends a suggested next step with one tap', function () {
    $this->actingAs($this->owner);

    askForInvitations($this->project);

    $run = $this->project->featureRequests()->sole()->latestRun;
    $run->update(['plan' => [...$run->plan, 'next' => ['Remind people who have not answered their invitation.']]]);

    visit(route('projects.show', ['project' => $this->project, 'change' => $run->feature_request_id]))
        ->click('@next-idea')
        ->assertSee('People are reminded of invitations they have not answered.')
        ->assertMissing('@thread-next')
        ->assertNoJavaScriptErrors();

    $followUp = $this->project->featureRequests()->whereNotNull('parent_id')->sole();
    expect($followUp->prompt)->toBe('Remind people who have not answered their invitation.');
});

it('starts the chat full screen and gives the app half once it is open', function () {
    $this->actingAs($this->owner);

    visit(route('projects.show', $this->project))
        ->assertVisible('@chat-full')
        ->assertVisible('@header-open-app')
        ->click('@chat-full')
        ->assertMissing('@header-open-app')
        ->click('@chat-full')
        ->assertVisible('@header-open-app')
        ->assertNoJavaScriptErrors();
});
