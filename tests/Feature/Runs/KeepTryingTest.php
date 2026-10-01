<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\CompleteRunVerification;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Jobs\ExecuteRun;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeepTryingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ExecuteRun::class, VerifyFeatureRequest::class]);
        config(['builder.construction.budgets.repairs' => 1]);
    }

    public function test_a_change_out_of_tries_keeps_trying_from_its_work_so_far_when_the_owner_asks()
    {
        $run = $this->outOfTries();
        $request = $run->featureRequest;

        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame(['reason' => 'verification_failed', 'details' => ["Tests failed:\nthe count is wrong."]], $run->feedback);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('featureRequest.can_keep_trying', true));

        $this->actingAs($request->user)
            ->post(route('feature-requests.keep-trying.store', $request))
            ->assertRedirect(route('projects.show', ['project' => $request->project, 'change' => $request->uuid]));

        $run->refresh();
        $this->assertSame(RunStatus::Implementing, $run->status);
        $this->assertSame(2, $run->repairs);
        $this->assertNull($run->error);
        // The next pass is told what to fix, and has as many tries again.
        $this->assertSame(["Tests failed:\nthe count is wrong."], $run->feedback['details']);
        $this->assertSame(2, $run->repairLimit());
        Queue::assertPushed(ExecuteRun::class);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_keep_trying', false)
                ->where('run.work', fn ($work) => collect($work)->contains('text', 'You asked me to keep trying, so I went back to fix it')));
    }

    public function test_only_a_change_that_ran_out_of_tries_can_keep_trying()
    {
        $run = $this->outOfTries();
        $run->update(['stop_reason' => 'budget_exhausted']);
        $request = $run->featureRequest;

        $this->actingAs($request->user)
            ->post(route('feature-requests.keep-trying.store', $request))
            ->assertSessionHasErrors('keep_trying');

        $this->assertSame(RunStatus::NeedsUserDecision, $run->refresh()->status);
    }

    public function test_only_people_who_may_ask_for_changes_can_keep_one_trying()
    {
        $run = $this->outOfTries();

        $this->actingAs(User::factory()->create())
            ->post(route('feature-requests.keep-trying.store', $run->featureRequest))
            ->assertForbidden();

        $this->assertSame(RunStatus::NeedsUserDecision, $run->refresh()->status);
    }

    /**
     * A change whose checks failed again after its one repair.
     */
    protected function outOfTries(): Run
    {
        $request = FeatureRequest::factory()->generated()->create();
        $run = Run::factory()->for($request)->create([
            'status' => RunStatus::Verifying,
            'driver' => 'worker',
            'repairs' => 1,
            'workspace_id' => Workspace::factory()->create()->id,
        ]);

        /** @var Verification $verification */
        $verification = $request->verifications()->create(['run_id' => $run->id, 'status' => VerificationStatus::Queued]);
        $verification->update(['status' => VerificationStatus::Failed, 'finished_at' => now(), 'results' => [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => 'the count is wrong.'],
        ]]);
        app(CompleteRunVerification::class)->handle($verification);

        return $run->refresh();
    }
}
