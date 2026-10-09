<?php

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Plan;

/*
| An owner sees what a change does, not how it is made. Technical details
| (the depths, the code, the tests and the developer tools) show only to
| someone who turns them on, and go away again when turned off.
*/

function plannedForOwner(bool $technical): FeatureRequest
{
    $change = FeatureRequest::factory()->create(['prompt' => 'Let members book a class.']);
    $change->project->owner->update(['technical_details' => $technical]);
    Run::factory()->for($change)->create([
        'status' => RunStatus::Planning,
        'plan' => Plan::fromArray([
            'summary' => 'Members can book a place in a class.',
            'acceptance_criteria' => ['A member books a place in a class with places left.'],
            'cases' => [],
            'written_tests' => [],
            'written_files' => [],
            'assumptions' => [],
            'tasks' => [],
            'steps' => [],
            'acceptance' => [],
            'solution_key' => null,
        ])->toArray(),
    ]);
    test()->actingAs($change->project->owner);

    return $change;
}

it('keeps how a change is made out of sight for an owner who did not ask', function () {
    $change = plannedForOwner(technical: false);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->resize(1440, 900)
        ->assertSee('Members can book a place in a class.')
        ->assertMissing('@detail-level')
        ->assertMissing('@beside-panel')
        ->assertMissing('@thread-details')
        ->assertMissing('@work-yourself-button')
        ->click('@app-menu')
        ->assertMissing('@own-tool-open')
        ->assertMissing('@details-open')
        ->assertNoJavaScriptErrors();
});

it('shows everything once the owner turns technical details on, and remembers it', function () {
    $change = plannedForOwner(technical: false);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->resize(1000, 900)
        ->assertMissing('@detail-level')
        ->click('@app-menu')
        ->click('@technical-details')
        ->assertPresent('@detail-level')
        ->assertPresent('@work-yourself-button')
        ->assertNoJavaScriptErrors();

    expect($change->project->owner->refresh()->technical_details)->toBeTrue();
});

it('hides the technical details again when the owner turns them off', function () {
    $change = plannedForOwner(technical: true);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->resize(1000, 900)
        ->assertPresent('@detail-level')
        ->click('@app-menu')
        ->click('@technical-details')
        ->assertMissing('@detail-level')
        ->assertMissing('@work-yourself-button')
        ->assertNoJavaScriptErrors();

    expect($change->project->owner->refresh()->technical_details)->toBeFalse();
});
