<?php

use App\Enums\VerificationStatus;
use App\Features\AppBoundaries;
use App\Models\FeatureRequest;
use App\Models\Verification;

/*
| An owner who wants what a boundary rule found, in a real browser: they
| read what it costs in the proof, say they want it, and can undo that,
| with the line staying where it was.
*/

it('lets the owner say they want what the checks found, and undo it', function () {
    $change = FeatureRequest::factory()->generated()->create();
    Verification::factory()->for($change)->create([
        'status' => VerificationStatus::Passed,
        'results' => [],
        'evidence' => [
            'traces' => ['requests' => 4, 'reached' => 2, 'unseen' => 0, 'existing' => 0, 'findings' => [], 'repeats' => []],
            'boundaries' => ['phased' => 10, 'unknown' => 0, 'existing' => 0, 'findings' => [
                ['kind' => AppBoundaries::CHANGED_WHILE_AUTHORIZING, 'route' => 'GET /posts', 'what' => 'insert refusals', 'at' => 'app/Policies/PostPolicy.php:12', 'in' => 'App\Policies\PostPolicy::view', 'test' => null],
            ]],
        ],
    ]);

    $this->actingAs($change->user);

    $page = visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertSeeIn('@change-proof-verdict', 'Checked, with gaps')
        ->assertSeeIn('@change-proof-gaps', 'while it checks who may do something')
        ->click('@change-proof-accept')
        ->assertSeeIn('@change-proof-chosen', 'You said you want this')
        ->assertMissing('@change-proof-gaps')
        ->assertDontSeeIn('@change-proof-verdict', 'with gaps')
        ->assertNoJavaScriptErrors();

    expect($change->acceptedFindings()->count())->toBe(1);

    $page->click('@change-proof-undo-accept')
        ->assertSeeIn('@change-proof-gaps', 'while it checks who may do something')
        ->assertMissing('@change-proof-chosen')
        ->assertNoJavaScriptErrors();

    expect($change->acceptedFindings()->count())->toBe(0);
});
