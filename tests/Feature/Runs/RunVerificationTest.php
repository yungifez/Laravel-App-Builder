<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\ExecuteRun;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class, VerifyFeatureRequest::class]);
        config(['builder.verification.retries' => 2, 'builder.construction.budgets.repairs' => 4]);
    }

    public function test_checks_stopped_on_our_side_run_again_without_costing_a_repair_and_then_say_it_is_our_fault()
    {
        $run = $this->verifyingRun();

        foreach ([1, 2] as $retry) {
            $this->finish($run, VerificationStatus::Errored, interrupted: true);

            $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
            $this->assertSame(0, $run->repairs);
            $this->assertNull($run->feedback);
            $this->assertCount($retry + 1, $run->verifications);
            $this->assertSame(VerificationStatus::Queued, $run->verifications()->latest('id')->first()?->status);
        }

        Queue::assertPushed(VerifyFeatureRequest::class, 2);

        $this->finish($run, VerificationStatus::Errored, interrupted: true);

        $this->assertSame(RunStatus::NeedsUserDecision, $run->refresh()->status);
        $this->assertSame('The checks could not run because of a problem on our side. This is our fault.', $run->error);
        $this->assertSame(0, $run->repairs);
        $this->assertSame(2, $run->events()->where('type', 'verification_retried')->count());
    }

    public function test_a_real_result_between_interruptions_starts_the_retries_again()
    {
        $run = $this->verifyingRun();

        $this->finish($run, VerificationStatus::Errored, interrupted: true);
        $this->finish($run, VerificationStatus::Errored, interrupted: true);
        $this->finish($run, VerificationStatus::Failed, results: [$this->check('Tests', 'failed', 'Teams have no description.')]);

        $this->assertSame(RunStatus::Implementing, $run->refresh()->status);
        $run->update(['status' => RunStatus::Verifying]);
        $run->featureRequest->verifications()->create(['run_id' => $run->id, 'status' => VerificationStatus::Queued]);

        $this->finish($run, VerificationStatus::Errored, interrupted: true);

        $this->assertSame(RunStatus::Verifying, $run->refresh()->status);
    }

    public function test_a_change_whose_only_problems_were_already_in_the_app_goes_on_to_review()
    {
        $run = $this->verifyingRun();

        $this->finish($run, VerificationStatus::Failed, results: [
            $this->check('Tests', 'passed', 'OK'),
            [...$this->check('Static analysis', 'failed', 'Found 3 errors'), 'at_start' => 'failed', 'new_problems' => []],
        ]);

        $this->assertSame(RunStatus::Reviewing, $run->refresh()->status);
        $this->assertSame(0, $run->repairs);
        $this->assertSame('failed_before', $run->events()->where('type', 'status')->reorder('sequence', 'desc')->first()?->data['reason']);
    }

    public function test_only_the_problems_the_change_brought_are_sent_back()
    {
        $run = $this->verifyingRun();

        $this->finish($run, VerificationStatus::Failed, results: [
            [...$this->check('Static analysis', 'failed', 'old problem and new problem'), 'at_start' => 'failed', 'new_problems' => ['Team::$description is not defined.']],
            [...$this->check('Tests', 'failed', 'old failure'), 'at_start' => 'failed', 'new_problems' => []],
            [...$this->check('PHP formatting', 'failed', 'app/Models/Team.php'), 'at_start' => 'passed'],
        ]);

        $run->refresh();
        $this->assertSame(RunStatus::Implementing, $run->status);
        $this->assertSame(1, $run->repairs);
        $this->assertSame([
            "Static analysis also fails without your change. These problems are new with it:\n- Team::\$description is not defined.",
            "PHP formatting failed:\napp/Models/Team.php",
        ], $run->feedback['details']);
    }

    protected function verifyingRun(): Run
    {
        $request = FeatureRequest::factory()->generated()->create();
        $run = Run::factory()->for($request)->create(['status' => RunStatus::Verifying, 'driver' => 'worker']);
        $request->verifications()->create(['run_id' => $run->id, 'status' => VerificationStatus::Queued]);

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    protected function finish(Run $run, VerificationStatus $status, bool $interrupted = false, array $results = []): void
    {
        /** @var Verification $verification */
        $verification = $run->verifications()->latest('id')->firstOrFail();
        $verification->update(['status' => $status, 'results' => $results, 'interrupted' => $interrupted, 'finished_at' => now()]);

        app(CompleteRunVerification::class)->handle($verification);
    }

    /**
     * @return array{name: string, stage: string, outcome: string, exit_code: int, timed_out: bool, duration_ms: int, output: string}
     */
    protected function check(string $name, string $outcome, string $output): array
    {
        return ['name' => $name, 'stage' => 'checks', 'outcome' => $outcome, 'exit_code' => $outcome === 'passed' ? 0 : 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => $output];
    }
}
