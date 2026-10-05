<?php

namespace Tests\Feature\Decisions;

use App\Actions\Decisions\ActOnDecision;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\Decision;
use App\Models\FeatureRequest;
use App\Models\Run;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActOnDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.decisions.act' => ['complexity']]);
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function decidedRun(array $decision = []): Run
    {
        $request = FeatureRequest::factory()->create();
        Decision::factory()->for($request)->create(['name' => 'complexity', 'choice' => 'trivial', 'confidence' => 0.95, 'threshold' => 0.9, ...$decision]);

        return Run::factory()->for($request)->create();
    }

    public function test_a_confident_answer_acts_once_switched_on_and_the_run_says_so()
    {
        $run = $this->decidedRun();

        $this->assertTrue(app(ActOnDecision::class)->handle($run, 'complexity', 'trivial'));

        $this->assertTrue($run->featureRequest->decisions()->sole()->acted);
        $this->assertSame(['name' => 'complexity', 'choice' => 'trivial', 'confidence' => 0.95], $run->events()->where('type', 'decision_acted')->sole()->data);
    }

    public function test_a_decision_switched_off_unsure_or_answering_otherwise_leaves_the_run_alone()
    {
        $act = fn (Run $run) => app(ActOnDecision::class)->handle($run, 'complexity', 'trivial');

        $this->assertFalse($act($this->decidedRun(['confidence' => 0.8])));
        $this->assertFalse($act($this->decidedRun(['choice' => 'normal'])));

        config(['builder.decisions.act' => []]);
        $run = $this->decidedRun();
        $this->assertFalse($act($run));

        $this->assertFalse(Decision::query()->where('acted', true)->exists());
        $this->assertSame(0, $run->events()->where('type', 'decision_acted')->count());
    }

    public function test_an_answer_not_back_yet_is_not_waited_for()
    {
        $run = Run::factory()->for(FeatureRequest::factory())->create();

        $this->assertFalse(app(ActOnDecision::class)->handle($run, 'complexity', 'trivial'));
    }

    public function test_the_report_sets_the_changes_a_decision_acted_on_against_those_it_left_alone()
    {
        foreach ([[true, VerificationStatus::Passed, 0.5], [true, VerificationStatus::Passed, 0.7], [false, VerificationStatus::Failed, 2.0]] as [$acted, $first, $cost]) {
            $request = FeatureRequest::factory()->generated()->create(['commit_sha' => fake()->sha1(), 'accepted_at' => now()]);
            Decision::factory()->for($request)->create(['name' => 'complexity', 'choice' => $acted ? 'trivial' : 'normal', 'acted' => $acted]);
            $run = Run::factory()->for($request)->create(['status' => RunStatus::Completed]);
            $run->recordEvent('model_call', ['role' => 'coder', 'cost_usd' => $cost]);
            $request->verifications()->create(['run_id' => $run->id, 'status' => $first]);
        }

        $this->artisan('builder:decisions')
            ->expectsTable(['Decision', 'Changes', 'Asked', 'First try passed', 'Kept', 'Cost per kept change'], [
                ['complexity', 'Acted', 2, '100% of 2', 2, '$0.60'],
                ['complexity', 'Left alone', 1, '0% of 1', 1, '$2.00'],
            ])
            ->assertSuccessful();
    }
}
