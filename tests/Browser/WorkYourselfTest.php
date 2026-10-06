<?php

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Jobs\DecideFeatureRequest;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Support\Facades\Queue;

/*
| The owner hands a change to their own Claude Code or Codex: the first
| click shows the connection, and connecting again makes a new one.
*/

function plannedChange(string $driver = 'scripted'): FeatureRequest
{
    Queue::fake([ExecuteRun::class, DecideFeatureRequest::class]);
    $change = FeatureRequest::factory()->create(['status' => FeatureRequestStatus::Generating, 'prompt' => 'Give teams a description.']);
    Run::factory()->for($change)->create(['status' => RunStatus::Planning, 'driver' => $driver]);
    test()->actingAs($change->project->owner);

    return $change;
}

it('shows the connection on the first click', function () {
    $change = plannedChange();

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->click('[data-test="work-yourself-button"]')
        ->assertSeeIn('[data-test="work-yourself-command"]', 'Authorization: Bearer ')
        ->assertMissing('[data-test="work-yourself-reconnect"]')
        ->assertNoJavaScriptErrors();
});

it('connects again once the shown connection is gone', function () {
    $change = plannedChange('worker');

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertSeeIn('[data-test="work-yourself-reconnect"]', 'Connect again')
        ->click('[data-test="work-yourself-reconnect"]')
        ->assertSeeIn('[data-test="work-yourself-command"]', 'Authorization: Bearer ')
        ->assertNoJavaScriptErrors();
});

it('quotes the token in the Codex command, so its "|" is not read as a pipe', function () {
    $change = plannedChange();

    $page = visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->click('[data-test="work-yourself-button"]')
        ->click('[data-test="work-yourself-codex"]');

    // Only whether it is quoted leaves the page, never the token.
    expect($page->script("/^export APP_CHANGE_TOKEN='\\d+\\|[^']+'; codex /.test(document.querySelector('[data-test=work-yourself-command]').textContent.trim())"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
