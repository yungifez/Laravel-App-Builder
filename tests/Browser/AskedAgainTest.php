<?php

use App\Enums\FeatureRequestStatus;
use App\Models\FeatureRequest;

/*
| A change that stopped and was asked again in its own words, not tried
| again, is the same ask, in a real browser: no filter says a change
| stopped while its ask goes on.
*/

it('counts a stopped change asked again only once, as the latest ask', function () {
    $stopped = FeatureRequest::factory()->create([
        'prompt' => 'Show a button to register when the feed is empty.',
        'status' => FeatureRequestStatus::Failed,
    ]);
    FeatureRequest::factory()->for($stopped->project)->for($stopped->user)->create([
        'prompt' => 'Show a button to register when the feed is empty.',
        'status' => FeatureRequestStatus::Generated,
    ]);

    $this->actingAs($stopped->user);

    visit(route('projects.show', $stopped->project))
        ->assertSee('To try')
        ->assertDontSee('Stopped')
        ->assertNoJavaScriptErrors();
});
