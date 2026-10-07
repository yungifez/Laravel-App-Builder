<?php

use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Verification;

/*
| A change whose checks passed, on its own page in a real browser: the
| proof gives the one verdict, with no second label beside it to disagree.
*/

it('gives one verdict once the checks pass', function () {
    $change = FeatureRequest::factory()->generated()->create();
    Verification::factory()->for($change)->create([
        'status' => VerificationStatus::Unverified,
        'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 5, 'output' => '', 'tests' => [
                ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_owners_rename_teams', 'outcome' => 'passed'],
            ]],
            ['name' => 'Protected acceptance tests', 'stage' => 'acceptance', 'outcome' => 'not_applicable', 'exit_code' => null, 'timed_out' => false, 'duration_ms' => 0, 'output' => ''],
        ],
    ]);

    $this->actingAs($change->user);

    visit(route('feature-requests.show', $change))
        ->assertSeeIn('@change-proof-verdict', 'Checked, with gaps')
        ->assertMissing('@verification-status')
        ->assertNoJavaScriptErrors();
});

/**
 * A change whose checks left a gap no one has to decide on.
 */
function changeWithAGap(): FeatureRequest
{
    $change = FeatureRequest::factory()->generated()->create();
    Verification::factory()->for($change)->create([
        'status' => VerificationStatus::Unverified,
        'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed', 'exit_code' => 0, 'timed_out' => false, 'duration_ms' => 5, 'output' => '', 'tests' => [
                ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_owners_rename_teams', 'outcome' => 'passed'],
            ]],
        ],
    ]);

    return $change;
}

it('folds the proof to its verdict until the owner opens it', function () {
    $change = changeWithAGap();
    $this->actingAs($change->user);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertSeeIn('@change-proof-verdict', 'Checked, with gaps')
        ->assertMissing('@change-proof-gaps')
        ->click('@change-proof-toggle')
        ->assertPresent('@change-proof-gaps')
        ->assertNoJavaScriptErrors();
});

it('opens the proof for an owner who reads a level deeper', function () {
    $change = changeWithAGap();
    $change->user->forceFill(['detail_level' => 2, 'technical_details' => true])->save();
    $this->actingAs($change->user);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertPresent('@change-proof-gaps')
        ->assertNoJavaScriptErrors();
});
