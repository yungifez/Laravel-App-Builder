<?php

namespace Tests\Feature\Context;

use App\Actions\Context\AssessVerifyItems;
use App\Runs\Plan;
use App\Runs\Review;
use Tests\TestCase;

class VerifyItemsTest extends TestCase
{
    protected const PATCH = "diff --git a/tests/Feature/TeamTest.php b/tests/Feature/TeamTest.php\n--- /dev/null\n+++ b/tests/Feature/TeamTest.php\n@@ -0,0 +1 @@\n+test('teams have a description')\n"
        ."diff --git a/resources/js/pages/Team.test.ts b/resources/js/pages/Team.test.ts\n--- /dev/null\n+++ b/resources/js/pages/Team.test.ts\n@@ -0,0 +1 @@\n+it('shows the description field')\n";

    public function test_only_a_test_the_suite_runs_counts_as_evidence()
    {
        $plan = new Plan('Describe teams.', ['Teams have a description.', 'The page shows the field.', 'Members cannot edit it.']);
        $review = new Review(true, 'Fine.', verify: [
            ['criterion' => 1, 'test_file' => 'tests/Feature/TeamTest.php', 'test_name' => 'teams have a description'],
            ['criterion' => 2, 'test_file' => 'resources/js/pages/Team.test.ts', 'test_name' => 'shows the description field'],
            ['criterion' => 3, 'test_file' => 'tests/Feature/MissingTest.php', 'test_name' => null],
        ]);

        $verified = app(AssessVerifyItems::class)->handle($plan, $review, self::PATCH, [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed'],
        ]);

        $this->assertSame(['tested', 'not_run_by_checks', 'no_test'], array_column($verified, 'evidence'));
        $this->assertTrue($verified[0]['named_in_diff']);
    }

    public function test_the_suite_paths_are_configurable()
    {
        config(['builder.verification.suite_paths' => ['tests/', 'resources/js/']]);
        $plan = new Plan('Describe teams.', ['The page shows the field.']);
        $review = new Review(true, 'Fine.', verify: [
            ['criterion' => 1, 'test_file' => 'resources/js/pages/Team.test.ts', 'test_name' => 'shows the description field'],
        ]);

        $verified = app(AssessVerifyItems::class)->handle($plan, $review, self::PATCH, [
            ['name' => 'Tests', 'stage' => 'checks', 'outcome' => 'passed'],
        ]);

        $this->assertSame('tested', $verified[0]['evidence']);
    }
}
