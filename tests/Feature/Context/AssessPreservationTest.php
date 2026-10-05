<?php

namespace Tests\Feature\Context;

use App\Actions\Context\AssessPreservation;
use App\Context\Capability;
use App\Context\ChangeClassification;
use App\Context\ProjectContext;
use App\Runs\Plan;
use Tests\TestCase;

class AssessPreservationTest extends TestCase
{
    /**
     * A passing suite whose report shows each teams test passed.
     */
    private const PASSED = [['name' => 'Tests', 'stage' => 'suite', 'outcome' => 'passed', 'tests' => [
        ['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'teams have a description', 'outcome' => 'passed'],
        ['file' => '/workspace/tests/Feature/TeamNameTest.php', 'name' => 'a team always has a name', 'outcome' => 'passed'],
    ]]];

    public function test_a_test_the_change_rewrote_is_not_evidence_that_the_old_behaviour_holds()
    {
        // The only test for teams was rewritten by the change, so a passing
        // suite says nothing about what teams did before.
        $preserved = $this->assess(
            ['teams' => ['tests/Feature/TeamTest.php']],
            new ChangeClassification(requested: ['teams' => ['app/Models/Team.php', 'tests/Feature/TeamTest.php']]),
        );

        $this->assertSame('not_checked', $preserved['evidence']);
        $this->assertSame(0, $preserved['tests']);
    }

    public function test_the_area_tests_the_change_left_alone_still_count()
    {
        $preserved = $this->assess(
            ['teams' => ['tests/Feature/TeamTest.php', 'tests/Feature/TeamNameTest.php']],
            new ChangeClassification(requested: ['teams' => ['app/Models/Team.php', 'tests/Feature/TeamTest.php']]),
        );

        $this->assertSame('verified', $preserved['evidence']);
        $this->assertSame(1, $preserved['tests']);
    }

    public function test_an_area_test_the_report_does_not_show_passing_is_not_evidence()
    {
        // A suite that passes without running the file (outside its folders,
        // or skipped) proves nothing about the area.
        $preserved = $this->assess(
            ['teams' => ['tests/Feature/TeamOwnerTest.php']],
            new ChangeClassification(requested: ['teams' => ['app/Models/Team.php']]),
        );

        $this->assertSame('not_checked', $preserved['evidence']);

        $withoutReport = $this->assess(
            ['teams' => ['tests/Feature/TeamNameTest.php']],
            new ChangeClassification(requested: ['teams' => ['app/Models/Team.php']]),
            [['name' => 'Tests', 'stage' => 'suite', 'outcome' => 'passed']],
        );

        $this->assertSame('not_checked', $withoutReport['evidence']);
    }

    public function test_a_rewritten_test_no_area_claims_is_left_out_too()
    {
        $preserved = $this->assess(
            ['teams' => ['tests/Feature/TeamTest.php']],
            new ChangeClassification(requested: ['teams' => ['app/Models/Team.php']], unclaimed: ['tests/Feature/TeamTest.php']),
        );

        $this->assertSame('not_checked', $preserved['evidence']);
    }

    public function test_an_area_the_change_did_not_touch_is_untouched_even_when_its_tests_were_rewritten()
    {
        $preserved = $this->assess(
            ['teams' => ['tests/Feature/TeamTest.php']],
            new ChangeClassification(requested: ['billing' => ['config/billing.php']], unclaimed: ['tests/Feature/TeamTest.php']),
        );

        $this->assertSame('untouched', $preserved['evidence']);
    }

    /**
     * Assess one "keep as it is" item about teams after a passing suite.
     *
     * @param  array<string, list<string>>  $testFiles
     * @param  list<array{name: string, stage: string, outcome: string, tests?: list<array{file: string, name: string, outcome: string}>}>  $results
     * @return array{area: string|null, statement: string, evidence: string, unchanged: bool, tests: int}
     */
    private function assess(array $testFiles, ChangeClassification $classification, array $results = self::PASSED): array
    {
        $context = new ProjectContext(capabilities: collect($testFiles)
            ->map(fn (array $files, string $key) => new Capability($key, ucfirst($key), testFiles: $files))
            ->all());
        $plan = new Plan('Add a team description.', preserve: [['area' => 'teams', 'statement' => 'A team always has a name.']]);

        return app(AssessPreservation::class)->handle($plan, $classification, $context, $results)[0];
    }
}
