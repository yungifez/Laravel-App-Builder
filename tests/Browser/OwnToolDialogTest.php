<?php

use App\Actions\Projects\ConnectOwnTool;
use App\Models\Project;

/*
| The owner connects their own tool to the whole app. The Claude app, VS
| Code and Cursor take the app's own address and sign in, so it shows at
| any time, unlike a terminal's connection, which shows once.
*/

it('gives the app address for the Claude app at any time', function () {
    $project = Project::factory()->create();
    app(ConnectOwnTool::class)->handle($project);
    $this->actingAs($project->owner);

    visit(route('projects.show', $project))
        ->click('[data-test="app-menu"]')
        ->click('[data-test="own-tool-open"]')
        // The terminal's connection showed when it was made, not now.
        ->assertVisible('[data-test="own-tool-connected"]')
        ->click('[data-test="own-tool-app"]')
        ->assertSeeIn('[data-test="own-tool-address-text"]', route('mcp.app', ['project' => $project->uuid]))
        ->assertMissing('[data-test="own-tool-connected"]')
        ->assertNoJavaScriptErrors();
});

it('shows no address before the owner connects their tool', function () {
    $project = Project::factory()->create();
    $this->actingAs($project->owner);

    visit(route('projects.show', $project))
        ->click('[data-test="app-menu"]')
        ->click('[data-test="own-tool-open"]')
        ->assertVisible('[data-test="own-tool-connect"]')
        ->assertMissing('[data-test="own-tool-app"]')
        ->assertMissing('[data-test="own-tool-address-text"]')
        ->assertNoJavaScriptErrors();
});
