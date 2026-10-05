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
            'cases' => [['base' => 'An owner invites a person by email.', 'alternate' => null, 'no_alternate' => 'Invites are only sent by email.', 'exception' => 'A member who tries to invite is turned away.', 'no_exception' => null]],
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
            'cases' => [['base' => 'An owner invites a person by email.', 'alternate' => null, 'no_alternate' => 'Invites are only sent by email.', 'exception' => 'A member who tries to invite is turned away.', 'no_exception' => null]],
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
            'cases' => [['base' => 'A customer picks a free time and it is booked.', 'alternate' => 'A customer changes the time before booking.', 'no_alternate' => null, 'exception' => 'A time already taken cannot be picked.', 'no_exception' => null]],
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
            'cases' => [['base' => 'An owner describes a team and sees it.', 'alternate' => 'An owner clears the description.', 'no_alternate' => null, 'exception' => 'A member who tries to describe a team is turned away.', 'no_exception' => null]],
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

    public function test_each_criterion_is_tried_three_ways_or_says_why_not()
    {
        $plan = Plan::fromModelOutput([
            'summary' => 'Show invoices.',
            'acceptance_criteria' => ['Owners see their invoices.', 'The total is shown in pounds.'],
            'cases' => [
                ['base' => 'An owner with two invoices sees both.', 'alternate' => 'An owner with none sees that there are none yet.', 'no_alternate' => 'ignored', 'exception' => 'Someone else is turned away.', 'no_exception' => null],
                ['base' => 'A total of 1250 shows as £12.50.', 'alternate' => null, 'no_alternate' => 'There is only one currency.', 'exception' => null, 'no_exception' => 'Nothing here can be refused.'],
            ],
            'assumptions' => [],
            'tasks' => ['Add an invoices page.'],
            'steps' => [['key' => 'page', 'kind' => 'interface', 'label' => 'The invoices page', 'file' => 'x', 'symbol' => 'x', 'detail' => 'x']],
        ], []);

        $this->assertSame(['base', 'alternate', 'exception', 'base', 'alternate', 'exception'], array_column($plan->cases, 'kind'));
        $this->assertSame([null, null, null, null, 'There is only one currency.', 'Nothing here can be refused.'], array_column($plan->cases, 'none'));
        $this->assertSame([
            ['criterion' => 'Owners see their invoices.', 'kind' => 'base', 'text' => 'Owners see their invoices. (base case: An owner with two invoices sees both.)'],
            ['criterion' => 'Owners see their invoices.', 'kind' => 'alternate', 'text' => 'Owners see their invoices. (alternate case: An owner with none sees that there are none yet.)'],
            ['criterion' => 'Owners see their invoices.', 'kind' => 'exception', 'text' => 'Owners see their invoices. (exception case: Someone else is turned away.)'],
            ['criterion' => 'The total is shown in pounds.', 'kind' => 'base', 'text' => 'The total is shown in pounds. (base case: A total of 1250 shows as £12.50.)'],
        ], $plan->verifyItems());
        $this->assertEquals($plan, Plan::fromArray($plan->toArray()));
    }

    public function test_missing_cases_a_case_left_out_without_a_reason_or_cases_out_of_step_are_refused()
    {
        $plan = fn (?array $cases) => Plan::fromModelOutput(array_filter([
            'summary' => 'Show invoices.',
            'acceptance_criteria' => ['Owners see their invoices.'],
            'cases' => $cases,
            'assumptions' => [],
            'tasks' => ['Add an invoices page.'],
            'steps' => [['key' => 'page', 'kind' => 'interface', 'label' => 'The invoices page', 'file' => 'x', 'symbol' => 'x', 'detail' => 'x']],
        ], fn ($value) => $value !== null), []);

        foreach ([
            null,
            [['base' => 'Both show.', 'alternate' => null, 'no_alternate' => null, 'exception' => 'Others are turned away.', 'no_exception' => null]],
            [],
            [['base' => '', 'alternate' => 'x', 'no_alternate' => null, 'exception' => 'y', 'no_exception' => null]],
        ] as $cases) {
            try {
                $plan($cases);
                $this->fail('A plan with cases '.json_encode($cases).' was accepted.');
            } catch (ConstructionFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_plan_with_unsafe_step_keys_or_missing_tasks_is_refused()
    {
        $this->expectException(ConstructionFailed::class);
        $this->expectExceptionMessageMatches('/tasks.*|steps\.0\.key/');

        Plan::fromModelOutput([
            'summary' => 'Invite people.',
            'acceptance_criteria' => ['Owners can invite.'],
            'cases' => [['base' => 'An owner invites a person by email.', 'alternate' => null, 'no_alternate' => 'Invites are only sent by email.', 'exception' => 'A member who tries to invite is turned away.', 'no_exception' => null]],
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

    public function test_a_review_with_a_sentence_too_long_to_show_is_shortened_not_refused()
    {
        $review = Review::fromModelOutput(['approved' => true, 'summary' => 'Fine.', 'findings' => [], 'changes' => [
            ['area' => 'Teams', 'behavior' => str_repeat('Members see a count. ', 20), 'before' => 'No count.', 'now' => 'A count.'],
        ]]);

        $this->assertTrue($review->approved);
        $this->assertSame(200, mb_strlen($review->changes[0]['behavior']));
        $this->assertStringEndsWith('…', $review->changes[0]['behavior']);
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
