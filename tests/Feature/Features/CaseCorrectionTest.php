<?php

namespace Tests\Feature\Features;

use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaseCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class]);
    }

    /**
     * A made change whose base and exception cases had tests written before
     * the build; its alternate case had none.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function change(array $attributes = []): FeatureRequest
    {
        $request = FeatureRequest::factory()->generated()->create(['prompt' => 'Show how many places each class has left.', ...$attributes]);
        Run::factory()->for($request)->create([
            'status' => RunStatus::Completed,
            'plan' => [
                'summary' => 'Each class shows how many places are left.', 'acceptance_criteria' => ['Each class shows its places left.'],
                'cases' => [
                    ['criterion' => 1, 'kind' => 'base', 'says' => 'A class with two of ten places taken shows eight left.', 'none' => null],
                    ['criterion' => 1, 'kind' => 'alternate', 'says' => 'A full class shows that it is full.', 'none' => null],
                    ['criterion' => 1, 'kind' => 'exception', 'says' => 'A class that does not exist is not found.', 'none' => null],
                ],
                'written_tests' => [
                    ['item' => 1, 'file' => 'tests/Feature/PlacesLeftTest.php', 'name' => 'test_a_class_shows_its_places_left'],
                    ['item' => 3, 'file' => 'tests/Feature/PlacesLeftTest.php', 'name' => 'test_a_missing_class_is_not_found'],
                ],
                'written_files' => ['tests/Feature/PlacesLeftTest.php' => '<?php'],
                'assumptions' => [], 'tasks' => [], 'steps' => [], 'acceptance' => [], 'solution_key' => null,
            ],
        ]);

        return $request;
    }

    private function correct(FeatureRequest $change, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $change->project->owner)->post(route('feature-requests.case-corrections.store', $change), [
            'criterion' => 1, 'kind' => 'base', 'note' => 'Places left counts only paid bookings.', ...$data,
        ]);
    }

    public function test_the_owner_sees_each_case_tested_first_and_a_wrong_one_makes_the_change_again_from_their_note()
    {
        $change = $this->change();

        $this->actingAs($change->project->owner)->get(route('feature-requests.show', $change))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_correct_cases', true)
                ->where('featureRequest.written_cases', [
                    ['criterion' => 1, 'kind' => 'base', 'says' => 'A class with two of ten places taken shows eight left.'],
                    ['criterion' => 1, 'kind' => 'exception', 'says' => 'A class that does not exist is not found.'],
                ]));

        $this->correct($change)->assertRedirect();

        $retry = FeatureRequest::query()->where('retry_of_id', $change->id)->sole();
        $run = $retry->latestRun;

        $this->assertSame($change->prompt, $retry->prompt);
        $this->assertSame([[
            'question' => 'Is this what you meant: “Each class shows its places left.: A class with two of ten places taken shows eight left.”?',
            'answer' => 'No. Places left counts only paid bookings.',
            'decided_by' => 'owner',
        ]], $run->answers);
        // The answer does not use up a question the planner may ask.
        $this->assertSame((int) config('builder.construction.questions.before_building') + 1, $run->question_limit);
        $this->assertSame(['change' => $change->uuid, 'criterion' => 1, 'kind' => 'base', 'note' => 'Places left counts only paid bookings.'], $run->events()->where('type', 'case_corrected')->sole()->data);
        Queue::assertPushed(ExecuteRun::class, fn (ExecuteRun $job) => $job->run->is($run));

        // The change it replaces now points to the new try, and is done.
        $this->actingAs($change->project->owner)->get(route('feature-requests.show', $change))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_correct_cases', false)
                ->where('featureRequest.tried_again', $retry->uuid));
    }

    public function test_a_follow_up_is_made_again_in_its_chat()
    {
        $parent = FeatureRequest::factory()->generated()->create();
        $change = $this->change(['project_id' => $parent->project_id, 'parent_id' => $parent->id]);

        $this->correct($change, ['kind' => 'exception', 'note' => 'A missing class sends people back to the list.'])->assertRedirect();

        $retry = FeatureRequest::query()->where('retry_of_id', $change->id)->sole();
        $this->assertTrue($retry->parent->is($parent));
        $this->assertStringContainsString('A class that does not exist is not found.', $retry->latestRun->answers[0]['question']);
    }

    public function test_a_case_with_no_test_written_first_a_kept_change_a_missing_note_or_a_stranger_changes_nothing()
    {
        $change = $this->change();

        // No test was written before the build for the alternate case.
        $this->correct($change, ['kind' => 'alternate'])->assertSessionHasErrors('note');
        $this->correct($change, ['criterion' => 2])->assertSessionHasErrors('note');
        $this->correct($change, ['note' => ''])->assertSessionHasErrors('note');
        $this->correct($change, as: User::factory()->create())->assertForbidden();

        $change->update(['commit_sha' => fake()->sha1(), 'accepted_at' => now()]);
        $this->correct($change->refresh())->assertSessionHasErrors('note');

        $this->assertSame(0, FeatureRequest::query()->whereNotNull('retry_of_id')->count());

        // A change built without tests written first has nothing to correct.
        $plain = $this->change();
        $plain->latestRun->update(['plan' => [...$plain->latestRun->plan, 'written_tests' => [], 'written_files' => []]]);

        $this->actingAs($plain->project->owner)->get(route('feature-requests.show', $plain))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_correct_cases', false)
                ->where('featureRequest.written_cases', []));
    }
}
