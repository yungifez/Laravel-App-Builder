<?php

namespace Tests\Feature\Runs;

use App\Features\TestChanges;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Plan;
use App\Runs\Review;
use Tests\TestCase;

class ModelOutputTest extends TestCase
{
    public function test_a_plan_keeps_only_the_expected_fields_and_takes_protected_suites_from_the_platform()
    {
        $plan = Plan::fromModelOutput([
            'summary' => 'Invite people.',
            'acceptance_criteria' => ['Owners can invite.'],
            'assumptions' => [],
            'tasks' => ['Add an invitation model.'],
            'steps' => [['key' => 'permission', 'kind' => 'permission', 'label' => 'Who may invite', 'file' => 'app/Policies/TeamPolicy.php', 'symbol' => 'TeamPolicy::invite', 'detail' => 'Owners only.', 'extra' => 'dropped']],
            'acceptance' => ['Invitations/Anything.php'],
        ], ['Invitations/InvitationContractTest.php']);

        $this->assertSame(['Invitations/InvitationContractTest.php'], $plan->acceptance);
        $this->assertArrayNotHasKey('extra', $plan->steps[0]);
        $this->assertEquals($plan, Plan::fromArray($plan->toArray()));
    }

    public function test_a_plan_with_unsafe_step_keys_or_missing_tasks_is_refused()
    {
        $this->expectException(ConstructionFailed::class);
        $this->expectExceptionMessageMatches('/tasks.*|steps\.0\.key/');

        Plan::fromModelOutput([
            'summary' => 'Invite people.',
            'acceptance_criteria' => ['Owners can invite.'],
            'assumptions' => [],
            'tasks' => [],
            'steps' => [['key' => '../Permission', 'kind' => 'permission', 'label' => 'x', 'file' => 'x', 'symbol' => 'x', 'detail' => 'x']],
        ], []);
    }

    public function test_a_review_with_a_blocking_finding_never_approves()
    {
        $review = Review::fromModelOutput([
            'approved' => true,
            'summary' => 'Fine.',
            'findings' => [['severity' => 'blocking', 'summary' => 'No policy check.', 'file' => null]],
        ]);

        $this->assertFalse($review->approved);
        $this->assertCount(1, $review->blockingFindings());
        $this->assertTrue(Review::fromModelOutput(['approved' => true, 'summary' => 'Fine.', 'findings' => [['severity' => 'minor', 'summary' => 'Naming.']]])->approved);
    }

    public function test_a_review_that_is_not_structured_as_expected_is_refused()
    {
        $this->expectException(ConstructionFailed::class);

        Review::fromModelOutput(['approved' => 'yes', 'summary' => 'Fine.', 'findings' => [['severity' => 'critical', 'summary' => 'x']]]);
    }

    public function test_deleted_and_weakened_tests_are_found()
    {
        $patch = implode("\n", [
            'diff --git a/tests/Feature/GoneTest.php b/tests/Feature/GoneTest.php',
            'deleted file mode 100644',
            '--- a/tests/Feature/GoneTest.php',
            '+++ /dev/null',
            '@@ -1 +0,0 @@',
            '-<?php',
            'diff --git a/tests/Feature/TeamTest.php b/tests/Feature/TeamTest.php',
            '--- a/tests/Feature/TeamTest.php',
            '+++ b/tests/Feature/TeamTest.php',
            '@@ -1,3 +1,2 @@',
            '     $response->assertOk();',
            '-    $response->assertForbidden();',
            '-    $this->assertDatabaseHas(\'teams\', []);',
            'diff --git a/tests/Feature/NewTest.php b/tests/Feature/NewTest.php',
            '--- a/tests/Feature/NewTest.php',
            '+++ b/tests/Feature/NewTest.php',
            '@@ -1 +1,2 @@',
            '+    $response->assertOk();',
            'diff --git a/app/Models/Team.php b/app/Models/Team.php',
            '--- a/app/Models/Team.php',
            '+++ b/app/Models/Team.php',
            '@@ -1 +0,0 @@',
            '-    // assert something',
            '',
        ]);

        $this->assertSame([
            ['path' => 'tests/Feature/GoneTest.php', 'deleted' => true, 'removed_assertions' => 0],
            ['path' => 'tests/Feature/TeamTest.php', 'deleted' => false, 'removed_assertions' => 2],
        ], TestChanges::weakened($patch));
    }
}
