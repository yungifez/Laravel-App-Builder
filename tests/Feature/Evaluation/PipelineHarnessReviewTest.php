<?php

namespace Tests\Feature\Evaluation;

use App\Actions\Runs\GatherReviewEvidence;
use App\Enums\FeatureRequestStatus;
use App\Enums\VerificationStatus;
use App\Evaluation\PipelineHarness;
use App\Features\MigrationChecks;
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
| test results, role probes and security findings included, and the
| platform's own checks and its gate block what they block there.
*/
class PipelineHarnessReviewTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<ReviewEvidence> */
    protected array $asked = [];

    protected Review $answer;

    public bool $canRepair = true;

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
                return $this->test->canRepair;
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

        // The security finding is also one the gate sends back.
        $this->assertFalse($review['approved']);
        $this->assertStringStartsWith('B1: ', $review['findings'][0]['summary']);
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

    public function test_the_platforms_own_checks_block_the_change_as_in_the_run_review()
    {
        [$run, $verification] = $this->verified([], unsafe: true, untested: true);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertFalse($review['approved']);
        $blocking = array_column(array_filter($review['findings'], fn (array $finding) => $finding['severity'] === 'blocking'), 'summary');
        $this->assertContains('No test in the change checks: Only the owner may remove people. (exception case: An admin is refused.)', $blocking);
        $this->assertCount(1, array_filter($blocking, fn (string $summary) => str_contains($summary, 'resources/views/team.blade.php')));
        $this->assertSame('no_test', $review['verified'][0]['evidence']);

        // Comparing reviewers asks only the reviewer.
        $judged = app(PipelineHarness::class)->judge($run, $verification);
        $this->assertTrue($judged['approved']);
        $this->assertSame([], $judged['verified']);
    }

    public function test_a_coder_that_cannot_repair_is_not_sent_findings_as_in_the_run_review()
    {
        $this->canRepair = false;
        [$run, $verification] = $this->verified([], unsafe: true, untested: true);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertTrue($review['approved']);
        $this->assertSame('no_test', $review['verified'][0]['evidence']);
    }

    public function test_checks_turned_off_block_nothing()
    {
        config(['builder.verification.require_verify_tests' => false, 'builder.verification.safety_scan' => false]);
        [$run, $verification] = $this->verified([], unsafe: true, untested: true);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertTrue($review['approved']);
        $this->assertSame([], $review['findings']);
    }

    public function test_a_page_the_change_made_too_wide_blocks_it_as_in_the_run_review()
    {
        [$run, $verification] = $this->verified([], screen: 'Teams');

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertFalse($review['approved']);
        $this->assertSame(['At 390 px wide, /teams (resources/js/pages/Teams.vue) scrolls sideways by 40 px. Make the page fit that width.'], array_column($review['findings'], 'summary'));
        $this->assertTrue(app(PipelineHarness::class)->judge($run, $verification)['approved']);
    }

    public function test_a_page_the_change_did_not_touch_blocks_nothing()
    {
        [$run, $verification] = $this->verified([], screen: 'Billing');

        $this->assertTrue(app(PipelineHarness::class)->review($run, $verification)['approved']);
    }

    public function test_the_screen_check_turned_off_blocks_nothing()
    {
        config(['builder.verification.screens.enabled' => false]);
        [$run, $verification] = $this->verified([], screen: 'Teams');

        $this->assertTrue(app(PipelineHarness::class)->review($run, $verification)['approved']);
    }

    public function test_the_gate_blocks_an_edited_migration_as_in_the_run_review()
    {
        [$run, $verification] = $this->verified(['migrations' => $this->editedMigration()]);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertFalse($review['approved']);
        $this->assertStringStartsWith('B1: The change edits database/migrations/0001_create_teams_table.php', $review['findings'][0]['summary']);
        $this->assertSame([], $review['asked']);
        $this->assertTrue(app(PipelineHarness::class)->judge($run, $verification)['approved']);
    }

    public function test_a_finding_the_agent_asked_to_keep_holds_the_change_until_the_owner_answers()
    {
        [$run, $verification] = $this->verified(['migrations' => $this->editedMigration()]);
        $run->featureRequest->findingProposals()->create(['run_id' => $run->id, 'kind' => MigrationChecks::EDITED, 'identity' => MigrationChecks::EDITED.'|database/migrations/0001_create_teams_table.php', 'reason' => 'It fixes a typo before launch.']);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertFalse($review['approved']);
        $this->assertSame([], $review['findings']);
        $this->assertCount(1, $review['asked']);
        $this->assertStringStartsWith('The change edits database/migrations/0001_create_teams_table.php', $review['asked'][0]);
    }

    public function test_a_finding_the_owner_kept_blocks_nothing()
    {
        [$run, $verification] = $this->verified(['migrations' => $this->editedMigration()]);
        $run->featureRequest->acceptedFindings()->create(['user_id' => $run->featureRequest->user_id, 'kind' => MigrationChecks::EDITED, 'identity' => MigrationChecks::EDITED.'|database/migrations/0001_create_teams_table.php']);

        $review = app(PipelineHarness::class)->review($run, $verification);

        $this->assertTrue($review['approved']);
        $this->assertSame([], $review['findings']);
        $this->assertSame([], $review['asked']);
    }

    /**
     * What the verification measured of a change that edits a migration
     * that already ran.
     *
     * @return array<string, mixed>
     */
    protected function editedMigration(): array
    {
        return ['added' => [], 'edited' => ['database/migrations/0001_create_teams_table.php'], 'up' => true, 'down' => true, 'again' => true, 'failed' => null, 'output' => null, 'risks' => []];
    }

    /**
     * Make a planned run and a finished verification of a copy of its change,
     * as the evaluation verifies a sabotaged patch.
     *
     * @param  array<string, mixed>  $evidence
     * @param  string|null  $screen  A measured page that scrolls sideways at 390 px; the change adds Teams.vue
     * @return array{Run, Verification}
     */
    protected function verified(array $evidence, bool $unsafe = false, bool $untested = false, ?string $screen = null): array
    {
        $patch = "diff --git a/config/teams.php b/config/teams.php\n";

        if ($screen !== null) {
            $patch .= "diff --git a/resources/js/pages/Teams.vue b/resources/js/pages/Teams.vue\nnew file mode 100644\n--- /dev/null\n+++ b/resources/js/pages/Teams.vue\n@@ -0,0 +1 @@\n+<template><main /></template>\n";
        }

        if ($unsafe) {
            $patch .= "diff --git a/resources/views/team.blade.php b/resources/views/team.blade.php\nnew file mode 100644\n--- /dev/null\n+++ b/resources/views/team.blade.php\n@@ -0,0 +1 @@\n".'+<p>{!! $team->name !!}</p>'."\n";
        }

        $original = FeatureRequest::factory()->create(['prompt' => 'Only the owner may remove people.', 'note_changes' => []]);
        $run = Run::factory()->for($original)->create(['driver' => 'test', 'plan' => (new Plan('Only the owner removes people.', acceptanceCriteria: ['Only the owner may remove people.'], cases: $untested ? [['criterion' => 1, 'kind' => 'exception', 'says' => 'An admin is refused.']] : []))->toArray()]);
        $copy = $original->project->featureRequests()->create([
            'user_id' => $original->user_id,
            'prompt' => $original->prompt,
            'status' => FeatureRequestStatus::Generated,
            'generator' => $original->generator,
            'patch' => $patch,
        ]);
        $verification = Verification::factory()->for($copy)->create([
            'status' => VerificationStatus::Failed,
            'results' => [['name' => 'Tests', 'stage' => 'check', 'outcome' => 'failed', 'exit_code' => 1, 'timed_out' => false, 'duration_ms' => 10, 'output' => '1 failed']],
            'evidence' => $evidence,
            'screens' => $screen === null ? null : ['pages' => [['screen' => $screen, 'path' => '/'.strtolower($screen), 'widths' => [['width' => 390, 'overflow' => 40]]]]],
        ]);

        return [$run, $verification];
    }
}
