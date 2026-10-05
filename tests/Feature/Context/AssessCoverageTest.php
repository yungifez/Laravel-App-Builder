<?php

namespace Tests\Feature\Context;

use App\Actions\Context\AssessCoverage;
use App\Context\Capability;
use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Runs\Plan;
use Tests\TestCase;

class AssessCoverageTest extends TestCase
{
    /**
     * A passing suite whose report shows the teams tests passed.
     */
    private const PASSED = [['name' => 'Tests', 'stage' => 'suite', 'outcome' => 'passed', 'tests' => [
        ['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'teams have a name', 'outcome' => 'passed'],
        ['file' => '/workspace/tests/Feature/TeamInviteTest.php', 'name' => 'owners invite people', 'outcome' => 'passed'],
    ]]];

    public function test_a_part_shows_which_ways_its_tests_proved()
    {
        $coverage = $this->assess([
            $this->verified('base', 'tests/Feature/TeamInviteTest.php'),
            $this->verified('exception', './tests/Feature/TeamInviteTest.php'),
        ]);

        $this->assertSame([[
            'area' => 'teams',
            'tests_passed' => 1,
            'cases' => ['base' => 'tested', 'alternate' => 'not_tested', 'exception' => 'tested'],
        ]], $coverage);
    }

    public function test_a_way_the_plan_does_not_need_is_not_asked_for()
    {
        $coverage = $this->assess(
            [$this->verified('base', 'tests/Feature/TeamInviteTest.php')],
            cases: [
                ['criterion' => 1, 'kind' => 'base', 'says' => 'An owner invites someone.', 'none' => null],
                ['criterion' => 1, 'kind' => 'alternate', 'says' => null, 'none' => 'There is only one way to invite.'],
                ['criterion' => 1, 'kind' => 'exception', 'says' => null, 'none' => 'Nothing can be refused.'],
            ],
        );

        $this->assertSame(['base' => 'tested', 'alternate' => 'not_needed', 'exception' => 'not_needed'], $coverage[0]['cases']);
    }

    public function test_a_test_that_proved_nothing_or_lives_in_another_part_does_not_count()
    {
        $coverage = $this->assess([
            // It passes with the change taken out, so it proves nothing.
            [...$this->verified('base', 'tests/Feature/TeamInviteTest.php'), 'evidence' => 'passes_without_change'],
            // It proved a way, but in billing's tests, not in teams'.
            $this->verified('alternate', 'tests/Feature/BillingTest.php'),
        ]);

        $this->assertSame(['base' => 'not_tested', 'alternate' => 'not_tested', 'exception' => 'not_tested'], $coverage[0]['cases']);
    }

    public function test_a_failed_suite_shows_no_passing_tests_and_unknown_parts_are_left_out()
    {
        $coverage = $this->assess([], [['name' => 'Tests', 'stage' => 'suite', 'outcome' => 'failed', 'tests' => self::PASSED[0]['tests']]], new ChangeClassification(
            requested: ['teams' => ['app/Models/Team.php']],
            unexpected: ['gone' => ['app/Gone.php']],
        ));

        $this->assertCount(1, $coverage);
        $this->assertSame(0, $coverage[0]['tests_passed']);
    }

    /**
     * Assess a change to teams that added an invitation test.
     *
     * @param  list<array{criterion: string, kind: string, case: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}>  $verified
     * @param  list<array{name: string, stage: string, outcome: string, tests?: list<array{file: string, name: string, outcome: string}>}>  $results
     * @param  list<array{criterion: int, kind: string, says: string|null, none: string|null}>|null  $cases
     * @return list<array{area: string, tests_passed: int, cases: array{base: string, alternate: string, exception: string}}>
     */
    private function assess(array $verified, array $results = self::PASSED, ?ChangeClassification $classification = null, ?array $cases = null): array
    {
        $context = new ProjectContext(capabilities: [
            'teams' => new Capability('teams', 'Teams', testFiles: ['tests/Feature/TeamTest.php']),
        ]);
        $plan = new Plan('Let owners invite people.', acceptanceCriteria: ['Owners can invite people.'], cases: $cases ?? array_map(
            fn (string $kind) => ['criterion' => 1, 'kind' => $kind, 'says' => "The {$kind} way.", 'none' => null],
            Plan::CASES,
        ));

        return app(AssessCoverage::class)->handle(
            $plan,
            $classification ?? new ChangeClassification(requested: ['teams' => ['app/Models/Team.php', 'tests/Feature/TeamInviteTest.php']]),
            $context,
            $verified,
            $results,
        );
    }

    /**
     * @return array{criterion: string, kind: string, case: string, test_file: string|null, test_name: string|null, evidence: string, named_in_diff: bool}
     */
    private function verified(string $kind, string $file): array
    {
        return ['criterion' => 'Owners can invite people.', 'kind' => $kind, 'case' => "The {$kind} way.", 'test_file' => $file, 'test_name' => 'owners invite people', 'evidence' => 'tested', 'named_in_diff' => true];
    }
}
