<?php

namespace Tests\Feature\Decisions;

use App\Actions\Decisions\MakeDecisions;
use App\Actions\Decisions\ObserveOutcome;
use App\Actions\Runs\StartRun;
use App\Enums\FeatureRequestStatus;
use App\Jobs\DecideFeatureRequest;
use App\Jobs\ExecuteRun;
use App\Models\Decision;
use App\Models\FeatureRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Tests\TestCase;

class DecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.typesafe.key' => 'typesafe-test-key', 'builder.decisions.providers' => ['typesafe']]);
    }

    public function test_starting_a_change_asks_for_decisions_before_the_run()
    {
        Queue::fake();

        app(StartRun::class)->handle(FeatureRequest::factory()->create());

        Queue::assertPushed(DecideFeatureRequest::class);
        $this->assertSame([DecideFeatureRequest::class, ExecuteRun::class], array_keys(Queue::pushedJobs()));
    }

    public function test_no_decisions_are_asked_for_without_a_decision_provider_key()
    {
        config(['ai.providers.typesafe.key' => null]);
        Queue::fake();

        app(StartRun::class)->handle(FeatureRequest::factory()->create());

        Queue::assertNotPushed(DecideFeatureRequest::class);
        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_the_decision_model_hears_only_the_owners_words_and_each_answer_is_kept_without_acting()
    {
        Classification::fake([[
            'complexity' => new ChoiceAnswer('trivial', ['trivial' => 0.93, 'normal' => 0.06, 'substantial' => 0.01], 0.93),
            'question' => new BooleanAnswer(0.02),
            'permissions' => new BooleanAnswer(0.55),
            'persisted_data' => new BooleanAnswer(0.1),
            'destructive' => new BooleanAnswer(0.01),
        ]]);
        $request = FeatureRequest::factory()->create(['prompt' => 'Make the save button green.']);

        app(MakeDecisions::class)->handle($request);

        Classification::assertClassified(fn (ClassificationPrompt $prompt) => $prompt->state === 'Make the save button green.' && $prompt->count() === 5);

        $decisions = $request->decisions()->get()->keyBy('name');
        $this->assertSame('trivial', $decisions['complexity']->choice);
        $this->assertTrue($decisions['complexity']->confident());
        $this->assertSame(['yes' => 0.02, 'no' => 0.98], $decisions['question']->probabilities);
        $this->assertSame(0.98, $decisions['question']->confidence);
        $this->assertSame('yes', $decisions['permissions']->choice);
        $this->assertFalse($decisions['permissions']->confident());
        $this->assertSame('typesafe', $decisions['complexity']->driver);
        $this->assertFalse($decisions->contains(fn (Decision $decision) => $decision->acted));
    }

    public function test_an_answered_question_is_observed_as_a_question_that_touched_nothing()
    {
        $request = FeatureRequest::factory()->create(['status' => FeatureRequestStatus::Answered]);

        $this->assertSame(
            ['question' => 'yes', 'complexity' => null, 'permissions' => 'no', 'persisted_data' => 'no', 'destructive' => 'no'],
            app(ObserveOutcome::class)->handle($request),
        );
    }

    public function test_the_outcome_comes_from_the_final_diff_and_a_new_tables_undo_is_not_destructive()
    {
        $request = FeatureRequest::factory()->generated()->create(['patch' => $this->diff([
            'database/migrations/2026_01_01_000000_create_notes_table.php' => ["Schema::create('notes', function (Blueprint \$table) {", '});', 'public function down(): void', "Schema::dropIfExists('notes');"],
            'app/Policies/NotePolicy.php' => ['return $user->id === $note->user_id;'],
            'tests/Feature/NoteTest.php' => ['// test'],
        ])]);

        $this->assertSame(
            ['question' => 'no', 'complexity' => 'trivial', 'permissions' => 'yes', 'persisted_data' => 'yes', 'destructive' => 'no'],
            app(ObserveOutcome::class)->handle($request),
        );

        $request->update(['patch' => $this->diff(['database/migrations/2026_01_01_000001_drop_bio.php' => ["\$table->dropColumn('bio');"]])]);

        $this->assertSame('yes', app(ObserveOutcome::class)->handle($request)['destructive']);
    }

    public function test_a_change_still_being_built_has_no_outcome_yet()
    {
        $this->assertSame([], app(ObserveOutcome::class)->handle(FeatureRequest::factory()->create()));
    }

    public function test_the_report_counts_confident_answers_that_were_right_and_wrong()
    {
        $answered = FeatureRequest::factory()->create(['status' => FeatureRequestStatus::Answered]);
        $built = FeatureRequest::factory()->generated()->create();
        Decision::factory()->for($answered)->create(['name' => 'question', 'choice' => 'yes', 'confidence' => 0.97]);
        Decision::factory()->for($built)->create(['name' => 'question', 'choice' => 'yes', 'confidence' => 0.95]);
        Decision::factory()->create(['name' => 'question', 'choice' => 'no', 'confidence' => 0.99]);

        $this->artisan('builder:decisions')
            ->expectsTable(
                ['Decision', 'Made', 'Outcome known', 'Confident (of known)', 'Confident and right', 'Confident and wrong', 'Average time'],
                [['question', 3, 2, 2, '50%', 1, '250 ms']],
            )
            ->assertSuccessful();
    }

    /**
     * Build a diff that adds the given lines to each file.
     *
     * @param  array<string, list<string>>  $files
     */
    protected function diff(array $files): string
    {
        return collect($files)->map(fn (array $lines, string $file) => "diff --git a/{$file} b/{$file}\n--- /dev/null\n+++ b/{$file}\n@@ -0,0 +1,".count($lines)." @@\n".collect($lines)->map(fn (string $line) => "+{$line}")->implode("\n"))->implode("\n");
    }
}
