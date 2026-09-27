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

    public function test_follow_up_ideas_are_short_distinct_and_at_most_three()
    {
        $plan = Plan::fromModelOutput([
            'summary' => 'Invite people.',
            'acceptance_criteria' => ['Owners can invite.'],
            'assumptions' => [],
            'tasks' => ['Add an invitation model.'],
            'steps' => [['key' => 'permission', 'kind' => 'permission', 'label' => 'Who may invite', 'file' => 'app/Policies/TeamPolicy.php', 'symbol' => 'TeamPolicy::invite', 'detail' => 'Owners only.']],
            'next' => [
                '  Remind   people who have not answered ',
                'Remind people who have not answered',
                '',
                str_repeat('Too long ', 20),
                'Let people leave a team',
                'Show who invited whom',
                'Limit invitations per day',
            ],
        ], []);

        $this->assertSame(['Remind people who have not answered', 'Let people leave a team', 'Show who invited whom'], $plan->next);
        $this->assertSame([], Plan::fromArray(array_diff_key($plan->toArray(), ['next' => true]))->next, 'A plan saved before ideas existed has none.');
    }

    public function test_a_plan_says_how_the_change_serves_the_owners_goal()
    {
        $output = [
            'summary' => 'Customers book online.',
            'acceptance_criteria' => ['Customers can pick a free time.'],
            'assumptions' => [],
            'tasks' => ['Add a booking form.'],
            'steps' => [['key' => 'form', 'kind' => 'interface', 'label' => 'Booking form', 'file' => 'resources/js/pages/Book.vue', 'symbol' => 'Book', 'detail' => 'Lists free times.']],
        ];

        $plan = Plan::fromModelOutput([...$output, 'goal' => ' Customers book without calling, so the front desk takes fewer calls. '], []);

        $this->assertSame('Customers book without calling, so the front desk takes fewer calls.', $plan->goal);
        $this->assertSame($plan->goal, Plan::fromArray($plan->toArray())->goal);
        // No goal in the notes, or a change that does not bear on it.
        $this->assertNull(Plan::fromModelOutput([...$output, 'goal' => ''], [])->goal);
        $this->assertNull(Plan::fromArray(array_diff_key($plan->toArray(), ['goal' => true]))->goal, 'A plan saved before goals existed has none.');
    }

    public function test_an_area_written_into_a_statement_is_moved_back_to_its_field()
    {
        $plan = Plan::fromModelOutput([
            'summary' => 'Describe teams.',
            'acceptance_criteria' => ['Owners can describe a team.'],
            'assumptions' => [],
            'tasks' => ['Add a description.'],
            'steps' => [['key' => 'field', 'kind' => 'data', 'label' => 'Description', 'file' => 'app/Models/Team.php', 'symbol' => 'Team', 'detail' => 'A new field.']],
            'preserve' => [
                ['area' => null, 'statement' => "Only owners and admins can change team settings.','area':'membership"],
                ['area' => 'teams', 'statement' => 'Renaming still works.", "area": "account'],
                ['area' => null, 'statement' => "The team's name stays required."],
                ['area' => null, 'statement' => "Switching teams works as before.','area':null"],
            ],
        ], []);

        $this->assertSame([
            ['area' => 'membership', 'statement' => 'Only owners and admins can change team settings.'],
            ['area' => 'teams', 'statement' => 'Renaming still works.'],
            ['area' => null, 'statement' => "The team's name stays required."],
            ['area' => null, 'statement' => 'Switching teams works as before.'],
        ], $plan->preserve);
        $this->assertSame($plan->preserve, Plan::fromArray([...$plan->toArray(), 'preserve' => [
            ['area' => null, 'statement' => "Only owners and admins can change team settings.','area':'membership"],
            ['area' => 'teams', 'statement' => 'Renaming still works.'],
            ['area' => null, 'statement' => "The team's name stays required."],
            ['area' => null, 'statement' => "Switching teams works as before.','area':null"],
        ]])->preserve, 'A plan saved before the fix reads back clean.');
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
