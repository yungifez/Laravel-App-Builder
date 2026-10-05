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
            'cases' => [['criterion' => 1, 'kind' => 'base', 'says' => 'A customer books a free time.', 'none' => null], ['criterion' => 1, 'kind' => 'alternate', 'says' => 'A customer books the last free time of the day.', 'none' => null], ['criterion' => 1, 'kind' => 'exception', 'says' => 'A time already taken cannot be booked.', 'none' => null]],
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
