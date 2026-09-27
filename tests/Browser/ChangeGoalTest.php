<?php

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Plan;

/*
| A change that serves the owner's goal, in a real browser: the owner reads
| how it helps, next to what the change does.
*/

it('shows how a change serves the owner\'s goal', function () {
    $change = FeatureRequest::factory()->create([
        'prompt' => 'Let customers book online.',
        'status' => FeatureRequestStatus::Generated,
    ]);
    Run::factory()->for($change)->create([
        'status' => RunStatus::Completed,
        'plan' => Plan::fromArray([
            'summary' => 'Customers pick a free time and book it themselves.',
            'acceptance_criteria' => ['Customers can book a free time.'],
            'assumptions' => [],
            'tasks' => ['Add a booking form.'],
            'steps' => [],
            'acceptance' => [],
            'solution_key' => null,
            'goal' => 'Customers book without calling, so the front desk takes fewer calls.',
        ])->toArray(),
    ]);

    $this->actingAs($change->user);

    visit(route('feature-requests.show', $change))
        ->assertSee('Customers pick a free time and book it themselves.')
        ->assertSeeIn('@run-goal', 'so the front desk takes fewer calls.')
        ->assertNoJavaScriptErrors();
});
