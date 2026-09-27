<?php

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Plan;

/*
| A question about the app, in a real browser: the owner reads the answer
| as an answer, not as a change waiting to be kept.
*/

it('shows an answer as answered, without a change to keep', function () {
    $question = FeatureRequest::factory()->create([
        'prompt' => 'Who can invite people?',
        'status' => FeatureRequestStatus::Answered,
    ]);
    Run::factory()->for($question)->create([
        'status' => RunStatus::Completed,
        'plan' => Plan::fromArray([
            'summary' => 'Only team owners can invite people.',
            'answer' => "Only a team's owner can invite people.",
            'understood_as' => 'Question',
            'current_behavior' => 'Owners invite members.',
            'acceptance_criteria' => [],
            'assumptions' => [],
            'tasks' => [],
            'steps' => [],
            'acceptance' => [],
            'solution_key' => null,
        ])->toArray(),
    ]);

    $this->actingAs($question->user);

    visit(route('feature-requests.show', $question))
        ->assertSee("Only a team's owner can invite people.")
        ->assertSee('Answered')
        ->assertDontSee('Ready for you')
        ->assertDontSee('Owners invite members.')
        ->assertMissing('@change-decision')
        ->assertNoJavaScriptErrors();
});
