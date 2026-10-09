<?php

use App\Enums\VerificationStatus;
use App\Features\ProductionCaches;
use App\Models\FeatureRequest;
use App\Models\Verification;
use Illuminate\Support\Facades\Queue;

/*
| An owner whose app could not go online before a change either, in a real
| browser: the line shows without opening the proof, and one tap asks for
| the fix and opens it.
*/

it('lets the owner ask to fix what kept the app from going online before the change', function () {
    Queue::fake();
    $change = FeatureRequest::factory()->generated()->create();
    Verification::factory()->for($change)->create([
        'status' => VerificationStatus::Passed,
        'results' => [
            ['name' => ProductionCaches::CHECK, 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 5, 'output' => 'route: Unable to prepare route [b] for serialization. Another route has already been assigned name [home].', 'at_start' => 'failed', 'new_problems' => []],
        ],
    ]);

    $this->actingAs($change->user);

    visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))
        ->assertSeeIn('@change-proof-gaps', 'could not go online before this change either: two pages share a name')
        ->click('@change-proof-fix')
        ->assertSee('Fix what keeps my app from going online.')
        ->assertNoJavaScriptErrors();

    $fix = FeatureRequest::query()->whereKeyNot($change->id)->sole();
    expect($fix->failed_checks['checks'][0]['name'] ?? null)->toBe(ProductionCaches::CHECK);
});
