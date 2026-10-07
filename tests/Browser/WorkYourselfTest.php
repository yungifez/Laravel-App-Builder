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
    // Handing a change to their own tool is a technical detail.
    $change->project->owner->update(['technical_details' => true]);
    test()->actingAs($change->project->owner);

    return $change;
}

it('shows the connection on the first click', function () {
    $change = plannedChange();

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->click('[data-test="work-yourself-button"]')
        ->assertSeeIn('[data-test="work-yourself-headless-copy"]', 'Copy the command')
        ->assertMissing('[data-test="work-yourself-reconnect"]')
        ->click('[data-test="work-yourself-other-ways"] summary')
        ->assertSeeIn('[data-test="work-yourself-command"]', 'Authorization: Bearer ')
        ->assertNoJavaScriptErrors();
});

it('connects again once the shown connection is gone', function () {
    $change = plannedChange('worker');

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertSeeIn('[data-test="work-yourself-reconnect"]', 'Connect again')
        ->assertMissing('[data-test="work-yourself-headless-copy"]')
        ->click('[data-test="work-yourself-reconnect"]')
        ->assertVisible('[data-test="work-yourself-headless-copy"]')
        ->click('[data-test="work-yourself-other-ways"] summary')
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

it('replaces only this folder\'s connection in the Claude Code command', function () {
    $change = plannedChange();

    $page = visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->click('[data-test="work-yourself-button"]');

    // A connection to the whole app under the same name stays, and the old
    // token in this folder is replaced rather than kept.
    expect($page->script("/^claude mcp remove --scope local \\S+ 2>\\/dev\\/null; claude mcp add --scope local --transport http /.test(document.querySelector('[data-test=work-yourself-command]').textContent.trim())"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('offers one command that makes the change on its own in a new temporary folder', function () {
    $change = plannedChange();

    $page = visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->click('[data-test="work-yourself-button"]');
    $shaped = fn (string $pattern) => $page->script("{$pattern}.test(document.querySelector('[data-test=work-yourself-headless]').textContent.trim())");

    // Only whether each is shaped right leaves the page, never the token.
    // Claude Code takes the connection on its command line, and only it.
    expect($shaped('/^\\(cd "\\$\\(mktemp -d\\)" && claude -p "Use the \\S+ tools: call get_task[^"]*" .* --strict-mcp-config --mcp-config \'\\{"mcpServers":\\{"[^"]+":\\{"type":"http","url":"http[^"]+","headers":\\{"Authorization":"Bearer \\d+\\|[^"\']+"\\}\\}\\}\\}\'\\)$/'))->toBeTrue();

    $page->click('[data-test="work-yourself-codex"]');
    // Codex has the token for this command alone.
    expect($shaped('/^APP_CHANGE_TOKEN=\'\\d+\\|[^\']+\' codex exec --cd "\\$\\(mktemp -d\\)" .*-c \'mcp_servers\\.\\S+\\.bearer_token_env_var="APP_CHANGE_TOKEN"\' .*"Use the \\S+ tools: call get_task[^"]*"$/'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('gives the app\'s address for the Claude app, even when the connection showed already', function () {
    $change = plannedChange('worker');

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertVisible('[data-test="work-yourself-reconnect"]')
        ->resize(390, 844)
        ->click('[data-test="work-yourself-app"]')
        ->assertSeeIn('[data-test="work-yourself-address"]', route('mcp.app', ['project' => $change->project->uuid]))
        ->assertSeeIn('[data-test="work-yourself-ask-app"]', 'call get_task')
        ->assertMissing('[data-test="work-yourself-reconnect"]')
        // The long address breaks, so the phone page never scrolls sideways.
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->click('[data-test="work-yourself-claude"]')
        ->assertVisible('[data-test="work-yourself-reconnect"]')
        ->assertNoJavaScriptErrors();
});

it('leads with one line and one button, the way that needs no copy of the app', function () {
    $change = plannedChange();

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->resize(390, 844)
        ->click('[data-test="work-yourself-button"]')
        ->assertSee('Paste this in a terminal. It makes the change on its own, in a new temporary folder.')
        ->assertVisible('[data-test="work-yourself-headless-copy"]')
        // The long commands wait behind "Other ways".
        ->assertMissing('[data-test="work-yourself-headless"]')
        ->assertMissing('[data-test="work-yourself-command"]')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});

it('keeps the copy of the app as another way, with what Codex needs', function () {
    $change = plannedChange();

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->resize(390, 844)
        ->click('[data-test="work-yourself-button"]')
        ->click('[data-test="work-yourself-codex"]')
        ->assertVisible('[data-test="work-yourself-headless-copy"]')
        ->click('[data-test="work-yourself-other-ways"] summary')
        ->assertVisible('[data-test="work-yourself-headless"]')
        ->assertSeeIn('[data-test="work-yourself-other-ways"]', 'Or, when you have a copy of the app, run this in it, and start Codex from the same terminal')
        // The long commands break, so the phone page never scrolls sideways.
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavaScriptErrors();
});
