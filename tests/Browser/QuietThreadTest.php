<?php

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Plan;

/*
| With nothing to decide, a change's chat stays almost silent: the
| decisions worth a glance are asked one at a time, and the rest of the
| plan waits behind one quiet link.
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
        ->assertMissing('[data-test="decisions-count"]')
        ->assertMissing('[data-test="decisions-next"]')
        ->assertDontSee('Times show in the gym’s time zone.')
        ->assertDontSee('A member books a place in a class with places left.')
        ->assertSeeIn('[data-test="plan-details-toggle"]', 'The plan')
        ->click('[data-test="plan-details-toggle"]')
        ->assertSee('Times show in the gym’s time zone.')
        ->assertSee('Classes list soonest first.')
        ->assertSee('A member books a place in a class with places left.')
        ->assertNoJavaScriptErrors();
});

it('asks about one decision at a time and moves on with Next', function () {
    $change = decidedChange(['First.', 'Second.', 'Third.'], []);

    quietChat($change)
        ->assertCount('[data-test="decision"]', 1)
        ->assertSeeIn('[data-test="decision"]', 'First.')
        ->assertDontSee('Second.')
        ->assertSeeIn('[data-test="decisions-count"]', '1 of 3')
        ->click('[data-test="decisions-next"]')
        ->assertSeeIn('[data-test="decision"]', 'Second.')
        ->assertSeeIn('[data-test="decisions-count"]', '2 of 3')
        ->click('[data-test="decisions-next"]')
        ->assertSeeIn('[data-test="decision"]', 'Third.')
        // The last one has nothing to move on to.
        ->assertMissing('[data-test="decisions-next"]')
        // What was moved past stays under the plan.
        ->click('[data-test="plan-details-toggle"]')
        ->assertSee('First.')
        ->assertNoJavaScriptErrors();
});

it('moves to the next decision once the owner agrees, and keeps the agreed one under the plan', function () {
    $change = decidedChange(['First.', 'Second.'], []);

    quietChat($change)
        ->click('[data-test="decision-keep"]')
        ->assertSeeIn('[data-test="decision"]', 'Second.')
        ->assertSeeIn('[data-test="decisions-count"]', '2 of 2')
        ->click('[data-test="decision-keep"]')
        ->assertMissing('[data-test="decisions"]')
        ->click('[data-test="plan-details-toggle"]')
        ->assertSee('First.')
        ->assertSee('Second.')
        ->assertCount('[data-test="decision-kept"]', 2)
        ->assertNoJavaScriptErrors();

    expect($change->latestRun->fresh()->kept_assumptions)->toBe(['First.', 'Second.']);
});

it('shows no decisions at all when nothing was decided for the owner', function () {
    $change = decidedChange([], []);

    quietChat($change)
        ->assertSee('Members can book a place in a class.')
        ->assertMissing('[data-test="decisions"]')
        ->assertMissing('[data-test="decision"]')
        ->assertNoJavaScriptErrors();
});

/**
 * A change waiting on the owner's answer to the given question.
 *
 * @param  array<string, mixed>  $question
 */
function askingChange(array $question): FeatureRequest
{
    $change = FeatureRequest::factory()->create(['prompt' => 'Let members book a class.']);
    Run::factory()->for($change)->create([
        'status' => RunStatus::NeedsUserDecision,
        'question' => [
            'why' => 'Once people have saved these, changing it means moving what they saved.',
            'options' => ['Yes, set it up this way', 'Make every detail optional'],
            'recommended' => 'Yes, set it up this way',
            'reversible' => false,
            ...$question,
        ],
    ]);

    return $change;
}

it('asks about a shape in one line with what lasts under it and the whole shape behind the plan', function () {
    $change = askingChange([
        'text' => 'Shall I set up bookings like this?',
        'glance' => ['Each booking must have the class.', 'How it went is one of pending or done.'],
        'details' => ['For each booking I keep: the class, how it went (pending or done) and a note if there is one.'],
    ]);

    quietChat($change)
        ->assertSeeIn('[data-test="question"]', 'Shall I set up bookings like this?')
        ->assertSeeIn('[data-test="question-glance"]', 'Each booking must have the class.')
        ->assertSeeIn('[data-test="question-glance"]', 'How it went is one of pending or done.')
        ->assertDontSee('a note if there is one')
        ->click('[data-test="question-plan-open"]')
        ->assertSeeIn('[data-test="question-details"]', 'a note if there is one')
        ->assertMissing('[data-test="question-plan-open"]')
        ->assertSee('Make every detail optional')
        ->assertNoJavaScriptErrors();
});

it('asks the planner’s own question with no glance lines and no plan link', function () {
    $change = askingChange(['text' => 'Can members book more than one place?']);

    quietChat($change)
        ->assertSeeIn('[data-test="question"]', 'Can members book more than one place?')
        ->assertMissing('[data-test="question-glance"]')
        ->assertMissing('[data-test="question-plan-open"]')
        ->assertNoJavaScriptErrors();
});

it('keeps the detail switch in place whichever level the owner picks', function () {
    $change = decidedChange(['Bookings are kept after a class is deleted.'], ['Times show in the gym’s time zone.']);
    $page = quietChat($change);
    // Where it sits in the chat, wherever the chat is scrolled to: a click
    // scrolls its button into view first.
    $at = fn () => (string) $page->script("(() => { const scroller = document.querySelector('[data-test=change-thread] .overflow-y-auto'); const box = document.querySelector('[data-test=detail-level]').getBoundingClientRect(); return Math.round(box.top - scroller.getBoundingClientRect().top + scroller.scrollTop) + ',' + Math.round(box.width); })()");

    $first = $at();

    foreach ([2, 3, 4, 1] as $level) {
        $page->click("@detail-{$level}");
        expect($at())->toBe($first);
    }

    $page->assertNoJavaScriptErrors();
});
