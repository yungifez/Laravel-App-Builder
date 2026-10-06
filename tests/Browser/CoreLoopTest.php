<?php

use App\Actions\Projects\CreateProject;
use App\Context\ProjectContext;
use App\Context\ProjectNotes;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
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

it('tells a new owner that each change is checked and can be undone', function () {
    $this->actingAs($this->owner);

    visit(route('projects.show', $this->project))
        ->assertSeeIn('@chat-promise', 'I check each change in your app before you see it. You keep it, or undo it any time.')
        ->fill('prompt', 'Let owners and admins invite people by email.')
        ->click('@request-feature-button')
        ->assertSee('Owners and admins can invite people.')
        ->assertMissing('@chat-promise')
        ->assertNoJavaScriptErrors();
});

it('tells an owner in a new idea that its changes stay there', function () {
    $this->actingAs($this->owner);

    visit(route('projects.show', $this->project))
        ->click('@app-menu')
        ->click('@idea-new')
        ->fill('name', 'Brighter colours')
        ->click('@idea-start')
        ->assertSeeIn('@chat-idea-promise', 'Changes you ask for here stay in Brighter colours. Your app stays as it is until you use the idea.')
        ->assertMissing('@chat-promise')
        ->assertNoJavaScriptErrors();
});

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

it('shows each level of detail on its own below desktop width', function () {
    $this->actingAs($this->owner);

    $page = askForInvitations($this->project);

    // A desktop puts every level beside the chat, so the switch is for
    // narrower screens.
    $page->resize(1000, 900)
        ->click('@detail-2')
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

it('tells the owner how the change was made, in plain words', function () {
    $this->actingAs($this->owner);

    askForInvitations($this->project);

    $run = $this->project->featureRequests()->sole()->latestRun;
    $run->recordEvent('agent_story', ['story' => [
        ['kind' => 'said', 'text' => 'Only team owners should send invitations, so I am adding that check first.'],
        ['kind' => 'read', 'file' => 'app/Policies/TeamPolicy.php'],
        ['kind' => 'testing'],
    ]]);

    visit(route('projects.show', ['project' => $this->project, 'change' => $run->featureRequest->uuid]))
        ->assertMissing('@thread-work')
        ->click('@thread-work-toggle')
        ->assertSeeIn('@thread-work', 'Only team owners should send invitations, so I am adding that check first.')
        ->assertSeeIn('@thread-work', 'Tried it out')
        ->assertDontSee('TeamPolicy')
        ->assertNoJavaScriptErrors();
});

it('sends a suggested next step with one tap', function () {
    $this->actingAs($this->owner);

    askForInvitations($this->project);

    $run = $this->project->featureRequests()->sole()->latestRun;
    $run->update(['plan' => [...$run->plan, 'next' => ['Remind people who have not answered their invitation.']]]);

    visit(route('projects.show', ['project' => $this->project, 'change' => $run->featureRequest->uuid]))
        ->click('@next-idea')
        ->assertSee('People are reminded of invitations they have not answered.')
        ->assertMissing('@thread-next')
        ->assertNoJavaScriptErrors();

    $followUp = $this->project->featureRequests()->whereNotNull('parent_id')->sole();
    expect($followUp->prompt)->toBe('Remind people who have not answered their invitation.');
});

it('puts the plan and the code beside a full-screen chat on a desktop, and the other chats too when wider', function () {
    $this->actingAs($this->owner);

    askForInvitations($this->project)
        ->resize(1440, 900)
        ->assertVisible('@chat-list')
        ->assertSeeIn('@chat-list', 'Let owners and admins invite people by email.')
        ->assertVisible('@beside-plan-content')
        ->assertMissing('@beside-code-content')
        ->assertMissing('@detail-level')
        ->click('@beside-code')
        ->assertSeeIn('@beside-code-content', 'Checks I ran')
        ->assertSeeIn('[data-test="beside-code-content"] [data-test="change-code"]', 'TeamPolicy.php')
        ->assertVisible('@accept-change-button')
        ->resize(1100, 900)
        ->assertMissing('@chat-list')
        ->assertVisible('@beside-panel')
        ->assertSeeIn('[data-test="beside-code-content"] [data-test="change-code"]', 'TeamPolicy.php')
        ->resize(1000, 900)
        ->assertMissing('@beside-panel')
        ->assertVisible('@detail-level')
        ->resize(1440, 900)
        ->assertSeeIn('[data-test="beside-code-content"] [data-test="change-code"]', 'TeamPolicy.php')
        ->assertNoJavaScriptErrors();
});

it('keeps the three columns in place when the owner moves to a chat without a plan yet', function () {
    $this->actingAs($this->owner);
    $planning = Run::factory()->for(
        FeatureRequest::factory()->for($this->project)->state(['prompt' => 'Show who was active last week.']),
    )->create()->featureRequest;

    askForInvitations($this->project)
        ->resize(1440, 900)
        ->assertMissing('@beside-waiting')
        ->click('@chat-list-'.$planning->uuid)
        ->assertSeeIn('@thread-title', 'Show who was active last week.')
        ->assertVisible('@chat-list')
        ->assertVisible('@beside-panel')
        ->assertSeeIn('@beside-waiting', 'The plan shows here once I know what to build.')
        ->assertNoJavaScriptErrors();
});

it('says a stopped chat will not get a plan, rather than promise one', function () {
    $this->actingAs($this->owner);
    $stopped = Run::factory()->for(
        FeatureRequest::factory()->for($this->project)->state(['prompt' => 'Show who was active last week.', 'status' => FeatureRequestStatus::Failed]),
    )->create(['status' => RunStatus::Failed, 'error' => 'The run stopped unexpectedly.'])->featureRequest;

    visit(route('projects.show', ['project' => $this->project, 'change' => $stopped->uuid]))
        ->resize(1440, 900)
        ->assertVisible('@beside-panel')
        ->assertSeeIn('@beside-waiting', 'This change stopped before it had a plan.')
        ->click('@beside-code')
        ->assertSeeIn('@beside-waiting', 'No code was changed.')
        ->assertNoJavaScriptErrors();
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

it('keeps what may also have changed apart from what was asked, and counts files no part claims', function () {
    $this->actingAs($this->owner);

    askForInvitations($this->project);

    $run = $this->project->featureRequests()->sole()->latestRun;
    $files = collect(PatchSummary::files($run->featureRequest->refresh()->patch))
        ->pluck('path')->reject(fn (string $path) => str_starts_with($path, ProjectNotes::directory().'/') || str_starts_with($path, ProjectContext::LEGACY_DIRECTORY.'/'))->values()->all();
    $review = $run->review;
    $review['changes'][] = ['behavior' => 'Seats are counted when someone joins.', 'before' => 'Seats were counted monthly.', 'now' => 'Seats are counted at once.', 'area' => 'billing', 'section' => 'may_also_affect', 'evidence' => 'in_change'];
    // Billing claims the first file; no part claims the others. No test
    // tried billing another way.
    $review['classification'] = [...$review['classification'], 'requested' => [], 'may_also_affect' => ['billing' => [$files[0]]], 'unexpected' => [], 'unclaimed' => array_slice($files, 1)];
    $review['coverage'] = [['area' => 'billing', 'tests_passed' => 1, 'cases' => ['base' => 'tested', 'alternate' => 'not_tested', 'exception' => 'not_needed']]];
    $run->update(['review' => $review]);

    visit(route('projects.show', ['project' => $this->project, 'change' => $run->featureRequest->uuid]))
        ->assertSeeIn('@review-may-also', 'Seats are counted when someone joins.')
        ->assertDontSeeIn('@run-review', 'Seats are counted when someone joins.')
        ->click('@beside-code')
        ->assertSeeIn('@detail-how', 'Not in any part of your app ('.(count($files) - 1).')')
        ->assertSeeIn('@area-untested', 'No test tried: Another way')
        ->assertNoJavaScriptErrors();
});
