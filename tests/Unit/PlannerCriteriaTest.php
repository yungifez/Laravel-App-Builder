<?php

namespace Tests\Unit;

use App\Ai\Agents\FeaturePlanner;
use App\Runs\Plan;
use Tests\TestCase;

/*
| Three first attempts went back for repair because the plan listed "behaves
| as before" as an acceptance criterion. Each criterion asks for a test that
| fails without the change, and nothing can fail for what the change does not
| touch, so what must keep working goes in preserve.
*/
class PlannerCriteriaTest extends TestCase
{
    public function test_criteria_are_only_what_the_change_adds_or_changes()
    {
        $instructions = (string) (new FeaturePlanner)->instructions();

        $this->assertStringContainsString('Each one is something the change adds or changes.', $instructions);
    }

    public function test_what_must_keep_working_goes_in_preserve()
    {
        $instructions = (string) (new FeaturePlanner)->instructions();

        $this->assertStringContainsString('What must keep working as it does goes in preserve, never here', $instructions);
        $this->assertStringContainsString('- preserve:', $instructions);
    }

    public function test_the_planner_sees_why_a_behaves_as_before_criterion_is_wrong()
    {
        $instructions = (string) (new FeaturePlanner)->instructions();

        $this->assertStringContainsString('a criterion such as "Sign-in behaves as before" asks for a test of what the change does not touch', $instructions);
    }

    public function test_a_criterion_that_repeats_a_preserve_item_is_dropped_with_its_cases()
    {
        $plan = Plan::fromModelOutput($this->plan(['Customers can book a free time.', 'Owners can still sign in.'], ['Owners can still sign in.']), []);

        $this->assertSame(['Customers can book a free time.'], $plan->acceptanceCriteria);
        $this->assertSame([1, 1, 1], array_column($plan->cases, 'criterion'));
        $this->assertSame('Book the 9:00 slot.', $plan->cases[0]['says']);
        $this->assertSame(['Owners can still sign in.'], array_column($plan->preserve, 'statement'));
    }

    public function test_case_spacing_and_a_full_stop_do_not_hide_a_repeat()
    {
        $plan = Plan::fromModelOutput($this->plan(['Customers can book a free time.', 'owners can  still sign in'], ['Owners can still sign in.']), []);

        $this->assertSame(['Customers can book a free time.'], $plan->acceptanceCriteria);

        // A criterion that only looks alike is about something else, so it stays.
        $alike = Plan::fromModelOutput($this->plan(['Customers can book a free time.', 'Owners can still sign in with a code.'], ['Owners can still sign in.']), []);

        $this->assertCount(2, $alike->acceptanceCriteria);
    }

    public function test_every_criterion_stays_when_all_of_them_repeat()
    {
        $plan = Plan::fromModelOutput($this->plan(['Owners can still sign in.'], ['Owners can still sign in.']), []);

        $this->assertSame(['Owners can still sign in.'], $plan->acceptanceCriteria);
        $this->assertCount(3, $plan->cases);
    }

    /**
     * A valid planner response with the given criteria and preserve items.
     *
     * @param  list<string>  $criteria
     * @param  list<string>  $preserve
     * @return array<string, mixed>
     */
    protected function plan(array $criteria, array $preserve): array
    {
        return [
            'summary' => 'Customers book times.',
            'acceptance_criteria' => $criteria,
            'cases' => array_map(fn (string $criterion) => ['base' => str_starts_with($criterion, 'Customers') ? 'Book the 9:00 slot.' : 'Sign in.', 'alternate' => null, 'no_alternate' => 'One way only.', 'exception' => null, 'no_exception' => 'Nothing is refused.'], $criteria),
            'assumptions' => [],
            'tasks' => ['Add booking.'],
            'steps' => [['key' => 'booking', 'kind' => 'data', 'label' => 'Booking', 'file' => 'app/Models/Booking.php', 'symbol' => 'Booking', 'detail' => 'Holds a booking.']],
            'preserve' => array_map(fn (string $statement) => ['area' => null, 'statement' => $statement], $preserve),
        ];
    }
}
