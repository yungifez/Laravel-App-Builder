<?php

namespace Tests\Unit\Context;

use App\Actions\Context\AssessPreservation;
use App\Context\Capability;
use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Features\TestResults;
use App\Runs\Plan;
use App\Runs\Review;
use Tests\TestCase;

class AssessPreservationTest extends TestCase
{
    protected ProjectContext $context;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        config(['builder.verification.suite_check' => 'Tests']);

        $this->context = new ProjectContext(capabilities: [
            'teams' => new Capability('teams', 'Teams', paths: ['app/Http/Requests/Settings/TeamUpdateRequest.php', 'tests/Feature/Teams/UpdateTeamTest.php'], testFiles: ['tests/Feature/Teams/UpdateTeamTest.php']),
            'membership' => new Capability('membership', 'Membership', paths: ['app/Policies/TeamPolicy.php', 'tests/Feature/Teams/SwitchCurrentTeamTest.php'], testFiles: ['tests/Feature/Teams/SwitchCurrentTeamTest.php']),
            'account' => new Capability('account', 'Account', paths: ['app/Models/User.php']),
        ]);

        $this->plan = new Plan('Let owners delete teams.', preserve: [
            ['area' => 'teams', 'statement' => 'Renaming a team keeps working.'],
            ['area' => 'membership', 'statement' => 'People cannot switch to teams they are not in.'],
            ['area' => 'account', 'statement' => 'Signing up keeps working.'],
        ]);
    }

    public function test_passing_related_tests_are_never_claimed_as_proof()
    {
        $preserved = $this->assess(new ChangeClassification(requested: ['membership' => ['app/Policies/TeamPolicy.php']]), $this->suite('passed'), $this->approved());

        $this->assertSame(['related_tests_passed', 'related_tests_passed', 'not_edited'], array_column($preserved, 'evidence'));
        $this->assertSame([true, false, true], array_column($preserved, 'unchanged'));
    }

    public function test_a_blocking_finding_overrides_passing_tests_in_the_area_it_names()
    {
        // The pilot's uncovered sabotage: every test passes, the reviewer
        // blocks the change for removing the team name's length limit.
        $review = new Review(false, 'Unrelated validation change.', [
            ['severity' => 'blocking', 'summary' => 'The max:255 rule was removed.', 'file' => 'app/Http/Requests/Settings/TeamUpdateRequest.php'],
        ]);

        $preserved = $this->assess(new ChangeClassification(requested: ['teams' => ['app/Http/Requests/Settings/TeamUpdateRequest.php']]), $this->suite('passed'), $review);

        $this->assertSame('regression_suspected', $preserved[0]['evidence']);
        $this->assertFalse($preserved[0]['review_objected']);
    }

    public function test_when_the_review_objects_no_item_reads_as_unchallenged()
    {
        $review = new Review(false, 'Something is wrong.', [['severity' => 'blocking', 'summary' => 'Verification is not convincing.', 'file' => null]]);

        $preserved = $this->assess(new ChangeClassification, $this->suite('passed'), $review);

        $this->assertSame([true, true, true], array_column($preserved, 'review_objected'));
    }

    public function test_an_unexpected_change_to_an_area_is_a_suspected_regression()
    {
        $preserved = $this->assess(new ChangeClassification(unexpected: ['membership' => ['app/Policies/TeamPolicy.php']]), $this->suite('passed'), $this->approved());

        $this->assertSame('regression_suspected', $preserved[1]['evidence']);
    }

    public function test_failing_tests_are_attributed_to_their_area_only()
    {
        $output = "   FAILED  Tests\\Feature\\Teams\\SwitchCurrentTeamTest > users cannot switch t…\n  Tests:    1 failed, 62 passed\n";

        $preserved = $this->assess(new ChangeClassification(requested: ['teams' => ['app/Http/Requests/Settings/TeamUpdateRequest.php']]), $this->suite('failed', $output), $this->approved());

        // Teams' own tests did not fail, but the suite did: nothing positive can be said.
        $this->assertSame(['not_checked', 'tests_failed', 'not_edited'], array_column($preserved, 'evidence'));
    }

    public function test_failing_test_names_map_to_their_files()
    {
        $output = "   FAILED  Tests\\Unit\\TeamRoleTest > permissions come from configuration\n   FAILED  Tests\\Feature\\Teams\\RemoveTeamMemberTest > the owner cannot be re…\n   FAILED  Tests\\Unit\\TeamRoleTest > another\n";

        $this->assertSame(['tests/Unit/TeamRoleTest.php', 'tests/Feature/Teams/RemoveTeamMemberTest.php'], TestResults::failingFiles($output));
    }

    /**
     * @param  list<array{name: string, stage: string, outcome: string, output?: string}>  $results
     * @return list<array<string, mixed>>
     */
    protected function assess(ChangeClassification $classification, array $results, Review $review): array
    {
        return (new AssessPreservation)->handle($this->plan, $classification, $this->context, $results, $review);
    }

    /**
     * @return list<array{name: string, stage: string, outcome: string, output: string}>
     */
    protected function suite(string $outcome, string $output = ''): array
    {
        return [['name' => 'Tests', 'stage' => 'checks', 'outcome' => $outcome, 'output' => $output]];
    }

    protected function approved(): Review
    {
        return new Review(true, 'Fine.');
    }
}
