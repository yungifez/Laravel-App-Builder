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
