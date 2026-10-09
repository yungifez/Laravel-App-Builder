<?php

use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;
use App\Models\Project;

/*
| Before an app has its first version there is nothing to open, so the
| designer says when it can be used instead of asking for the app.
*/

it('says the look can change once the first version is ready, while it is made', function () {
    $project = Project::factory()->create(['started_here' => true]);
    FeatureRequest::factory()->for($project)->create(['prompt' => 'Make the first version: A booking page.', 'status' => FeatureRequestStatus::Generating]);

    $this->actingAs($project->owner);

    visit(route('projects.show', ['project' => $project, 'design' => 1]))
        ->assertSeeIn('[data-test="panel"] [data-test="inspector"]', 'You can change the look once your first version is ready')
        ->assertDontSee('Open your app first')
        ->assertNoJavaScriptErrors();
});

it('asks for the app to be opened when it has a version that is not open', function () {
    $project = Project::factory()->create(['started_here' => false]);

    $this->actingAs($project->owner);

    visit(route('projects.show', ['project' => $project, 'design' => 1]))
        ->assertSeeIn('[data-test="panel"] [data-test="inspector"]', 'Open your app first')
        ->assertDontSee('once your first version is ready')
        ->assertNoJavaScriptErrors();
});

it('still says when the look can change after the first version stopped', function () {
    $project = Project::factory()->create(['started_here' => true]);
    FeatureRequest::factory()->for($project)->create(['prompt' => 'Make the first version: A booking page.', 'status' => FeatureRequestStatus::Failed, 'error' => 'The coding service crashed.']);

    $this->actingAs($project->owner);

    visit(route('projects.show', ['project' => $project, 'design' => 1]))
        ->assertSee('Your first version could not be made')
        ->assertSeeIn('[data-test="panel"] [data-test="inspector"]', 'You can change the look once your first version is ready')
        ->assertDontSee('Open your app first')
        ->assertNoJavaScriptErrors();
});
