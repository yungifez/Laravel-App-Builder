<?php

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Plan;

/*
| With nothing to decide, a change's chat stays almost silent: the
| decisions worth a glance show one line each, and the rest of the plan
| waits behind one quiet link.
*/

/**
 * A change waiting to be built whose plan decided the given things for
 * the owner: those that cannot be undone are worth a glance, the rest
 * are quiet.
 *
 * @param  list<string>  $glance
 * @param  list<string>  $quiet
 */
function decidedChange(array $glance, array $quiet): FeatureRequest
{
    $change = FeatureRequest::factory()->create(['prompt' => 'Let members book a class.']);
    Run::factory()->for($change)->create([
        'status' => RunStatus::Planning,
        'plan' => Plan::fromArray([
            'summary' => 'Members can book a place in a class.',
            'acceptance_criteria' => ['A member books a place in a class with places left.'],
            'cases' => [],
            'written_tests' => [],
            'written_files' => [],
            'assumptions' => [
                ...array_map(fn (string $text) => ['text' => $text, 'touches' => [], 'reversible' => false, 'easier_after_seeing' => false], $glance),
                ...array_map(fn (string $text) => ['text' => $text, 'touches' => [], 'reversible' => true, 'easier_after_seeing' => false], $quiet),
            ],
            'tasks' => [],
            'steps' => [],
            'acceptance' => [],
            'solution_key' => null,
        ])->toArray(),
    ]);

    return $change;
}

function quietChat(FeatureRequest $change): mixed
{
    test()->actingAs($change->project->owner);

    return visit(route('projects.show', ['project' => $change->project, 'change' => $change->uuid]))->resize(390, 844);
}

it('shows one line for a decision worth a glance and keeps the rest behind the plan', function () {
    $change = decidedChange(['Bookings are kept after a class is deleted.'], ['Times show in the gym’s time zone.', 'A member books one place at a time.', 'Classes list soonest first.']);

    quietChat($change)
        ->assertCount('[data-test="decision"]', 1)
        ->assertSee('Bookings are kept after a class is deleted.')
        ->assertVisible('[data-test="decision-keep"]')
        ->assertVisible('[data-test="decision-change"]')
        ->assertMissing('[data-test="decisions-more"]')
        ->assertDontSee('Times show in the gym’s time zone.')
        ->assertDontSee('A member books a place in a class with places left.')
        ->assertSeeIn('[data-test="plan-details-toggle"]', 'The plan')
        ->click('[data-test="plan-details-toggle"]')
        ->assertSee('Times show in the gym’s time zone.')
        ->assertSee('Classes list soonest first.')
        ->assertSee('A member books a place in a class with places left.')
        ->assertNoJavaScriptErrors();
});

it('shows three decisions worth a glance and says how many more there are', function () {
    $change = decidedChange(['First.', 'Second.', 'Third.', 'Fourth.', 'Fifth.'], []);

    quietChat($change)
        ->assertCount('[data-test="decision"]', 3)
        ->assertDontSee('Fourth.')
        ->assertSeeIn('[data-test="decisions-more"]', '2 more')
        ->click('[data-test="decisions-more"]')
        ->assertCount('[data-test="decision"]', 5)
        ->assertSee('Fifth.')
        ->assertMissing('[data-test="decisions-more"]')
        ->assertNoJavaScriptErrors();
});

it('shows no decisions at all when nothing was decided for the owner', function () {
    $change = decidedChange([], []);

    quietChat($change)
        ->assertSee('Members can book a place in a class.')
        ->assertMissing('[data-test="decisions"]')
        ->assertMissing('[data-test="decision"]')
        ->assertNoJavaScriptErrors();
});
