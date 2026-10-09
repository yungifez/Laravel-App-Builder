<?php

use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Verification;

/*
| Checks that could not run, in a real browser: the owner reads why, so
| "Check again" does not look like it does nothing.
*/

it('says why the checks could not run', function () {
    $change = FeatureRequest::factory()->generated()->create();
    Verification::factory()->for($change)->create([
        'status' => VerificationStatus::Errored,
        'error' => 'The change does not apply to the project.',
        'results' => [],
    ]);

    $this->actingAs($change->user);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertSee('Checks could not run')
        ->assertSeeIn('@verification-error', 'The change does not apply to the project.')
        ->assertSee('Check again')
        ->assertNoJavaScriptErrors();
});
