<?php

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Runs\Plan;

/*
| While the first version is made, the app pane draws the app taking shape
| from what is known: its name, then its parts as the coder makes them.
| It is a drawing with nothing to click, and says it is being built.
*/

it('draws the app taking shape while its first version is made', function () {
    $project = Project::factory()->create(['started_here' => true, 'name' => 'Studio Classes']);
    $change = FeatureRequest::factory()->for($project)->create(['prompt' => 'Make the first version: Members book a class.', 'status' => FeatureRequestStatus::Generating]);
    $run = Run::factory()->for($change)->create(['status' => RunStatus::Implementing, 'plan' => (new Plan('Members book a class.', acceptanceCriteria: ['Members book a class.'], steps: [
        ['key' => 'classes', 'kind' => 'page', 'label' => 'Class timetable', 'file' => 'resources/js/pages/Classes.vue', 'symbol' => 'Classes', 'detail' => 'The classes.'],
        ['key' => 'bookings', 'kind' => 'page', 'label' => 'Bookings', 'file' => 'resources/js/pages/Bookings.vue', 'symbol' => 'Bookings', 'detail' => 'The bookings.'],
    ]))->toArray()]);
    $run->recordEvent('agent_story', ['story' => [['kind' => 'changed', 'file' => 'resources/js/pages/Classes.vue']]]);

    $this->actingAs($project->owner);

    foreach ([[1280, 800], [768, 1024], [390, 844]] as [$width, $height]) {
        $page = visit(route('projects.show', ['project' => $project, 'change' => $change->uuid]))->resize($width, $height);

        // Below a desktop the app is a screen of its own.
        if ($width < 1024) {
            $page->click('[data-test="view-app"]');
        }

        $page->assertSeeIn('[data-test="first-version-sketch"] [data-test="sketch-name"]', 'Studio Classes')
            ->assertSeeIn('[data-test="sketch-part-made"]', 'Class timetable')
            ->assertSeeIn('[data-test="sketch-part"]', 'Bookings')
            ->assertSeeIn('[data-test="first-version-making"]', 'Making the first version of your app')
            ->assertNoJavaScriptErrors();
    }
});
