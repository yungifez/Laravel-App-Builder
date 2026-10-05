<?php

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Plan;
use Illuminate\Support\Facades\Queue;

/*
| The owner reads what each test written before the build tries, in the
| plan's words, and says one is not what they meant: the change is made
| again from their note.
*/

it('makes a change again without a case the owner did not mean', function () {
    Queue::fake([ExecuteRun::class]);

    $change = FeatureRequest::factory()->generated()->create(['prompt' => 'Show how many places each class has left.']);
    Run::factory()->for($change)->create([
        'status' => RunStatus::Completed,
        'plan' => Plan::fromArray([
            'summary' => 'Each class shows how many places are left.',
            'acceptance_criteria' => ['Each class shows its places left.'],
            'cases' => [
                ['criterion' => 1, 'kind' => 'base', 'says' => 'A class with two of ten places taken shows eight left.', 'none' => null],
                ['criterion' => 1, 'kind' => 'alternate', 'says' => 'A full class shows that it is full.', 'none' => null],
                ['criterion' => 1, 'kind' => 'exception', 'says' => 'A class that does not exist is not found.', 'none' => null],
            ],
            'written_tests' => [['item' => 1, 'file' => 'tests/Feature/PlacesLeftTest.php', 'name' => 'test_a_class_shows_its_places_left']],
            'written_files' => ['tests/Feature/PlacesLeftTest.php' => '<?php'],
            'assumptions' => [],
            'tasks' => [],
            'steps' => [],
            'acceptance' => [],
            'solution_key' => null,
        ])->toArray(),
    ]);

    $this->actingAs($change->project->owner);

    visit(route('feature-requests.show', $change))
        ->click('[data-test="review-verified"] button')
        ->assertSee('A class with two of ten places taken shows eight left.')
        ->assertDontSee('PlacesLeftTest')
        ->click('[data-test="not-meant"]')
        ->fill('note', 'Places left counts only paid bookings.')
        ->click('[data-test="not-meant-submit"]')
        ->assertPathBeginsWith('/projects/')
        ->assertNoJavaScriptErrors();

    $retry = FeatureRequest::query()->where('retry_of_id', $change->id)->sole();
    expect($retry->latestRun->answers[0]['answer'])->toBe('No. Places left counts only paid bookings.');
});
