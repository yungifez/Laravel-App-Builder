<?php

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;

/*
| The bell says what the chat says: a question still waiting needs the
| owner, though its note was read.
*/

it('counts a waiting question on the bell after its note was read', function () {
    $change = FeatureRequest::factory()->create(['prompt' => 'Let members invite friends.']);
    Run::factory()->for($change)->create([
        'status' => RunStatus::NeedsUserDecision,
        'question' => ['text' => 'Who can invite?', 'why' => '', 'options' => ['Owners', 'Everyone'], 'recommended' => null],
    ]);
    $this->actingAs($change->user);

    visit(route('projects.index'))
        ->assertSeeIn('[data-test="notifications-unread"]', '1')
        ->assertAttribute('[data-test="notifications"]', 'aria-label', '1 things need you')
        ->click('[data-test="notifications"]')
        ->assertSeeIn("[data-test=\"waiting-{$change->uuid}\"]", 'Who can invite?')
        ->assertDontSee('Nothing needs you')
        ->assertNoJavaScriptErrors();
});

it('says nothing needs the owner once the question is answered', function () {
    $change = FeatureRequest::factory()->create(['prompt' => 'Let members invite friends.']);
    Run::factory()->for($change)->create(['status' => RunStatus::Planning, 'question' => null]);
    $this->actingAs($change->user);

    visit(route('projects.index'))
        ->assertMissing('[data-test="notifications-unread"]')
        ->assertAttribute('[data-test="notifications"]', 'aria-label', 'Nothing needs you')
        ->assertNoJavaScriptErrors();
});
