<?php

namespace Tests\Feature\Context;

use App\Actions\Context\AssessVerifyItems;
use App\Features\TestReport;
use App\Runs\Plan;
use App\Runs\Review;
use Tests\TestCase;

class VerifyItemsTest extends TestCase
{
    protected const PATCH = "diff --git a/tests/Feature/TeamTest.php b/tests/Feature/TeamTest.php\n--- /dev/null\n+++ b/tests/Feature/TeamTest.php\n@@ -0,0 +1 @@\n+test('teams have a description')\n"
        ."diff --git a/resources/js/pages/Team.test.ts b/resources/js/pages/Team.test.ts\n--- /dev/null\n+++ b/resources/js/pages/Team.test.ts\n@@ -0,0 +1 @@\n+it('shows the description field')\n";

    public function test_only_a_test_the_suite_ran_and_passed_counts_as_evidence()
    {
        $plan = new Plan('Describe teams.', ['Teams have a description.', 'The page shows the field.', 'Members cannot edit it.'], cases: $this->usualWayOnly(3));
        $review = new Review(true, 'Fine.', verify: [
            ['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'teams have a description'],
            ['criterion' => 2, 'test_file' => 'resources/js/pages/Team.test.ts', 'test_name' => 'shows the description field'],
            ['criterion' => 3, 'test_file' => 'tests/Feature/MissingTest.php', 'test_name' => null],
        ]);

        $verified = app(AssessVerifyItems::class)->handle($plan, $review, self::PATCH, [
            $this->suite('passed', [['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'teams have a description', 'outcome' => 'passed']]),
        ]);

        $this->assertSame(['tested', 'not_run_by_checks', 'no_test'], array_column($verified, 'evidence'));
        $this->assertTrue($verified[0]['named_in_diff']);
    }

    public function test_a_named_test_that_did_not_run_is_not_evidence()
    {
        $plan = new Plan('Describe teams.', ['Teams have a description.'], cases: $this->usualWayOnly(1));
        $review = new Review(true, 'Fine.', verify: [
            ['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'teams have a description'],
        ]);
        $evidence = fn (array $results) => app(AssessVerifyItems::class)->handle($plan, $review, self::PATCH, $results)[0]['evidence'];

        // Skipped, missing from the report, or a different test in the file.
        $this->assertSame('not_run_by_checks', $evidence([$this->suite('passed', [['file' => 'tests/Feature/TeamTest.php', 'name' => 'teams have a description', 'outcome' => 'skipped']])]));
        $this->assertSame('not_run_by_checks', $evidence([$this->suite('passed', [])]));
        $this->assertSame('not_run_by_checks', $evidence([$this->suite('passed', [['file' => 'tests/Feature/TeamTest.php', 'name' => 'teams have a name', 'outcome' => 'passed']])]));
        // It ran and failed, or the suite failed.
        $this->assertSame('not_run', $evidence([$this->suite('passed', [['file' => 'tests/Feature/TeamTest.php', 'name' => 'teams have a description', 'outcome' => 'failed']])]));
        $this->assertSame('not_run', $evidence([$this->suite('failed', [['file' => 'tests/Feature/TeamTest.php', 'name' => 'teams have a description', 'outcome' => 'passed']])]));
        // No report: only the reviewer says it ran.
        $this->assertSame('claimed', $evidence([['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed']]));
        // PHPUnit method names match the reviewer's words, and so do data sets.
        $this->assertSame('tested', $evidence([$this->suite('passed', [
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_teams_have_a_description with data set "admin"', 'outcome' => 'passed'],
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_teams_have_a_description with data set "member"', 'outcome' => 'passed'],
        ])]));
    }

    public function test_each_case_needs_its_own_test_that_shows_the_change_and_a_refusal_for_the_exception()
    {
        $plan = new Plan('Describe teams.', ['Teams have a description.'], cases: [
            ['criterion' => 1, 'kind' => 'base', 'says' => 'An owner adds one.', 'none' => null],
            ['criterion' => 1, 'kind' => 'alternate', 'says' => null, 'none' => 'There is no other way.'],
            ['criterion' => 1, 'kind' => 'exception', 'says' => 'A member is turned away.', 'none' => null],
        ]);
        $review = new Review(true, 'Fine.', verify: [
            ['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'owners add a description'],
            ['criterion' => 2, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'members cannot add a description'],
        ]);
        $suite = $this->suite('passed', [
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_owners_add_a_description', 'outcome' => 'passed'],
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_members_cannot_add_a_description', 'outcome' => 'passed'],
        ]);
        $verified = fn (array $evidence) => app(AssessVerifyItems::class)->handle($plan, $review, self::PATCH, [$suite], $evidence);

        $items = $verified([]);
        $this->assertSame(['base', 'exception'], array_column($items, 'kind'));
        $this->assertSame(['Teams have a description.', 'Teams have a description.'], array_column($items, 'criterion'));
        $this->assertSame(['tested', 'tested'], array_column($items, 'evidence'), 'Without measurements, a passing test is all there is.');

        // The member's request was let through, or never made.
        $this->assertSame('not_refused', $verified(['refusals' => ['teamtest|members_cannot_add_a_description' => false]])[1]['evidence']);
        $this->assertSame('no_request', $verified(['refusals' => ['teamtest|owners_add_a_description' => false]])[1]['evidence']);
        $this->assertSame('tested', $verified(['refusals' => ['teamtest|members_cannot_add_a_description' => true]])[1]['evidence']);

        // A new test that passes with the change taken out shows nothing.
        $this->assertSame('passes_without_change', $verified(['new_tests' => [
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_owners_add_a_description', 'without_change' => 'passed'],
        ]])[0]['evidence']);
        $this->assertSame('tested', $verified(['new_tests' => [
            ['file' => 'tests/Feature/TeamTest.php', 'name' => 'test_owners_add_a_description', 'without_change' => 'failed'],
        ]])[0]['evidence']);
    }

    public function test_the_suite_paths_are_configurable()
    {
        config(['builder.verification.suite_paths' => ['tests/', 'resources/js/'], 'builder.verification.suite_suffixes' => ['Test.php', '.test.ts']]);
        $plan = new Plan('Describe teams.', ['The page shows the field.'], cases: $this->usualWayOnly(1));
        $review = new Review(true, 'Fine.', verify: [
            ['criterion' => 1, 'test_file' => 'resources/js/pages/Team.test.ts', 'test_name' => 'shows the description field'],
        ]);

        $verified = app(AssessVerifyItems::class)->handle($plan, $review, self::PATCH, [
            $this->suite('passed', [['file' => 'resources/js/pages/Team.test.ts', 'name' => 'shows the description field', 'outcome' => 'passed']]),
        ]);

        $this->assertSame('tested', $verified[0]['evidence']);
    }

    public function test_the_junit_report_is_read_for_phpunit_and_pest_tests()
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Tests\Feature\TeamTest" file="/workspace/tests/Feature/TeamTest.php">
            <testcase name="test_teams_have_a_description" file="/workspace/tests/Feature/TeamTest.php" class="Tests\Feature\TeamTest"/>
            <testcase name="test_members_cannot_edit" class="Tests\Feature\TeamTest"><failure>Expected 403.</failure></testcase>
            <testcase name="test_later" file="/workspace/tests/Feature/TeamTest.php"><skipped/></testcase>
          </testsuite>
          <testsuite name="Tests\Feature\PageTest">
            <testcase name="it shows the field" file="tests/Feature/PageTest.php::it shows the field"><error>Boom</error></testcase>
          </testsuite>
        </testsuites>
        XML;

        $this->assertSame([
            ['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'test_teams_have_a_description', 'outcome' => 'passed'],
            ['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'test_members_cannot_edit', 'outcome' => 'failed'],
            ['file' => '/workspace/tests/Feature/TeamTest.php', 'name' => 'test_later', 'outcome' => 'skipped'],
            ['file' => 'tests/Feature/PageTest.php', 'name' => 'it shows the field', 'outcome' => 'failed'],
        ], TestReport::fromJunit($xml));
        $this->assertSame([], TestReport::fromJunit('not xml'));
    }

    /**
     * @param  list<array{file: string, name: string, outcome: string}>  $tests
     * @return array{name: string, stage: string, outcome: string, tests: list<array{file: string, name: string, outcome: string}>}
     */
    protected function suite(string $outcome, array $tests): array
    {
        return ['name' => 'Tests', 'stage' => 'checks', 'outcome' => $outcome, 'tests' => $tests];
    }

    /**
     * Cases where only the usual way applies, so each criterion is one item.
     *
     * @return list<array{criterion: int, kind: string, says: string|null, none: string|null}>
     */
    protected function usualWayOnly(int $criteria): array
    {
        $cases = [];

        foreach (range(1, $criteria) as $criterion) {
            array_push(
                $cases,
                ['criterion' => $criterion, 'kind' => 'base', 'says' => "The usual way of {$criterion}.", 'none' => null],
                ['criterion' => $criterion, 'kind' => 'alternate', 'says' => null, 'none' => 'There is no other way.'],
                ['criterion' => $criterion, 'kind' => 'exception', 'says' => null, 'none' => 'Nothing is refused.'],
            );
        }

        return $cases;
    }
}
