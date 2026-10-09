<?php

namespace Tests\Feature\Evaluation;

use App\Actions\Runs\GatherReviewEvidence;
use App\Enums\FeatureRequestStatus;
use App\Enums\VerificationStatus;
use App\Evaluation\PipelineHarness;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\Verification;
use App\Runs\ConstructionDriverManager;
use App\Runs\Contracts\ConstructionDriver;
use App\Runs\Plan;
use App\Runs\PlanningContext;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Runs\ToolSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/*
| The evaluation reviews a change the way the run's review stage does, so
| it measures the review that ships: the reviewer gets the same evidence,
| test results, role probes and security findings included.
*/
class PipelineHarnessReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<ReviewEvidence> */
    protected array $asked = [];

    protected Review $answer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->answer = new Review(true, 'Fine.');
        $test = $this;

        app(ConstructionDriverManager::class)->extend('test', fn () => new class($test) implements ConstructionDriver
        {
            public function __construct(protected PipelineHarnessReviewTest $test) {}

            public function plan(Run $run, PlanningContext $context): Plan
            {
                return new Plan('A test change.');
            }

            public function shape(Run $run, Plan $plan, PlanningContext $context): Plan
            {
                return $plan;
            }

            public function build(Run $run, Plan $plan, ToolSession $tools): string
            {
                return '';
            }

            public function review(Run $run, ReviewEvidence $evidence): Review
            {
                return $this->test->asked($evidence);
            }

            public function canRepair(): bool
            {
                return true;
            }
        });
    }

    /**
     * Record what the reviewer was given and answer for it.
     */
    public function asked(ReviewEvidence $evidence): Review
    {
        $this->asked[] = $evidence;

        return $this->answer;
    }

    public function test_the_reviewer_gets_the_verifications_evidence_as_the_run_review_does()
    {
        [$run, $verification] = $this->verified([
            'roles' => ['tried' => 4, 'changed' => [], 'new' => [], 'findings' => [['route' => 'team-members.destroy', 'actor' => 'member', 'before' => 'refused', 'after' => 'allowed']]],
            'boundaries' => ['phased' => 1, 'unknown' => 0, 'existing' => 0, 'findings' => [['kind' => 'unscoped', 'route' => 'team-members.destroy', 'what' => 'Membership is read without the team.', 'at' => 'app/Http/Controllers/TeamMemberController.php:40', 'in' => null, 'test' => null]]],
        ]);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertTrue($review['approved']);
        $this->assertCount(1, $this->asked);
        $evidence = $this->asked[0];
        $this->assertSame('member', $evidence->changeEvidence['roles']['findings'][0]['actor'] ?? null);
        $this->assertSame('unscoped', $evidence->changeEvidence['boundaries']['findings'][0]['kind'] ?? null);
        $this->assertSame('failed', $evidence->verificationStatus);
        $this->assertSame('Tests', $evidence->verificationResults[0]['name']);
        $this->assertSame($verification->featureRequest->patch, $evidence->patch);
        $this->assertSame($run->featureRequest->instructions(), $evidence->request);

        // The same evidence the run's review stage gathers for this change.
        $this->assertEquals(app(GatherReviewEvidence::class)->handle($run, Plan::fromArray((array) $run->plan), $verification), $evidence);
    }

    public function test_a_blocking_finding_about_a_new_test_that_only_guards_is_minor_as_in_the_run_review()
    {
        [$run, $verification] = $this->verified(['new_tests' => [
            ['file' => 'tests/Feature/RemoveTest.php', 'name' => 'test_owners_can_sign_in', 'without_change' => 'passed'],
            ['file' => 'tests/Feature/RemoveTest.php', 'name' => 'test_members_cannot_remove_people', 'without_change' => 'failed'],
        ]]);
        $this->answer = new Review(false, 'One gap.', [['severity' => 'blocking', 'summary' => 'test_owners_can_sign_in passes without the change.', 'file' => null]]);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertTrue($review['approved']);
        $this->assertSame('minor', $review['findings'][0]['severity']);
    }

    public function test_a_run_without_a_plan_is_not_reviewed()
    {
        [$run, $verification] = $this->verified([]);
        $run->update(['plan' => null]);

        try {
            app(PipelineHarness::class)->review($run, $verification);
            $this->fail('A run without a plan was reviewed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The run has no plan.', $exception->getMessage());
        }

        $this->assertSame([], $this->asked);
    }

    public function test_checks_standing_in_for_a_verification_give_the_reviewer_nothing_more()
    {
        [$run] = $this->verified([]);
        $checked = new Verification(['status' => 'passed', 'results' => [], 'evidence' => []]);
        $checked->setRelation('featureRequest', (new FeatureRequest(['patch' => "diff --git a/b b/b\n"]))->setRelation('project', $run->featureRequest->project));

        app(PipelineHarness::class)->review($run, $checked);

        $this->assertSame([], $this->asked[0]->changeEvidence);
        $this->assertSame('passed', $this->asked[0]->verificationStatus);
        $this->assertSame("diff --git a/b b/b\n", $this->asked[0]->patch);
    }

    /**
     * Make a planned run and a finished verification of a copy of its change,
     * as the evaluation verifies a sabotaged patch.
     *
     * @param  array<string, mixed>  $evidence
     * @return array{Run, Verification}
     */
    protected function verified(array $evidence): array
    {
        $original = FeatureRequest::factory()->create(['prompt' => 'Only the owner may remove people.', 'note_changes' => []]);
        $run = Run::factory()->for($original)->create(['driver' => 'test', 'plan' => (new Plan('Only the owner removes people.', acceptanceCriteria: ['Only the owner may remove people.']))->toArray()]);
        $copy = $original->project->featureRequests()->create([
            'user_id' => $original->user_id,
            'prompt' => $original->prompt,
            'status' => FeatureRequestStatus::Generated,
            'generator' => $original->generator,
            'patch' => "diff --git a/config/teams.php b/config/teams.php\n",
        ]);
        $verification = Verification::factory()->for($copy)->create([
            'status' => VerificationStatus::Failed,
            'results' => [['name' => 'Tests', 'stage' => 'check', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => '1 failed']],
            'evidence' => $evidence,
        ]);

        return [$run, $verification];
    }
}
