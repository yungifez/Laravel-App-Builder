<?php

namespace Tests\Feature\Runs;

use App\Enums\AssumptionLevel;
use App\Enums\Consequence;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Runs\Assumption;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What was decided for the owner is sorted by code, not the model: a
 * decision that touches something that matters or cannot be undone is worth
 * a glance and comes first; the rest stay quiet.
 */
class AssumptionLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_decision_about_money_that_cannot_be_undone_is_worth_a_glance_and_comes_first()
    {
        $plan = $this->plan([
            ['text' => 'Bookings use the default room', 'touches' => [], 'reversible' => true, 'easier_after_seeing' => true],
            ['text' => 'Who may cancel is the owner', 'touches' => ['access'], 'reversible' => true, 'easier_after_seeing' => false],
            ['text' => 'A deposit of 20% is taken', 'touches' => ['money'], 'reversible' => false, 'easier_after_seeing' => false],
            ['text' => 'Old bookings are deleted', 'touches' => ['data_loss'], 'reversible' => true, 'easier_after_seeing' => false],
        ]);

        $this->assertSame(AssumptionLevel::Glance, $plan->assumptions[2]->level());
        $this->assertSame(
            ['A deposit of 20% is taken', 'Old bookings are deleted', 'Who may cancel is the owner', 'Bookings use the default room'],
            array_map(fn (Assumption $assumption) => $assumption->text, Assumption::byAttention($plan->assumptions)),
        );

        // The thread gets the order and the level; the page sorts nothing.
        $request = FeatureRequest::factory()->create();
        Run::factory()->for($request)->create(['plan' => $plan->toArray()]);

        $this->actingAs($request->user)
            ->get(route('feature-requests.show', $request))
            ->assertInertia(fn (Assert $page) => $page->where('run.plan.assumptions', [
                ['text' => 'A deposit of 20% is taken', 'level' => 'glance'],
                ['text' => 'Old bookings are deleted', 'level' => 'glance'],
                ['text' => 'Who may cancel is the owner', 'level' => 'glance'],
                ['text' => 'Bookings use the default room', 'level' => 'quiet'],
            ]));
    }

    public function test_a_reversible_decision_that_touches_nothing_stays_quiet()
    {
        $plan = $this->plan([['text' => 'Rooms are listed by name', 'touches' => [], 'reversible' => true, 'easier_after_seeing' => false]]);

        $this->assertSame(AssumptionLevel::Quiet, $plan->assumptions[0]->level());
        // It is saved and read back as it was.
        $this->assertEquals($plan, Plan::fromArray($plan->toArray()));
    }

    public function test_a_touch_that_is_not_a_consequence_is_dropped_and_the_decision_stays_quiet()
    {
        $plan = $this->plan([['text' => 'Rooms have a colour', 'touches' => ['vibes', 'Money'], 'reversible' => true, 'easier_after_seeing' => false]]);

        $this->assertSame([], $plan->assumptions[0]->touches);
        $this->assertSame(AssumptionLevel::Quiet, $plan->assumptions[0]->level());

        // A question built on its recommendation keeps what it touches.
        $decided = $this->planned([], ['text' => 'Can rooms be paid for?', 'why' => '', 'options' => ['Yes', 'No'], 'recommended' => 'No', 'touches' => ['money', 'nope'], 'reversible' => true, 'easier_after_seeing' => true])->decidedOnRecommendation();
        $this->assertSame([Consequence::Money], $decided->assumptions[0]->touches);
        $this->assertSame(AssumptionLevel::Glance, $decided->assumptions[0]->level());
    }

    public function test_a_decision_the_planner_gives_as_plain_text_is_worth_a_glance()
    {
        $plan = $this->planned(['  Phone numbers are optional  ']);

        $this->assertEquals([new Assumption('Phone numbers are optional', [], reversible: false)], $plan->assumptions);
        $this->assertSame(AssumptionLevel::Glance, $plan->assumptions[0]->level());
        $this->assertEquals($plan, Plan::fromArray($plan->toArray()));
    }

    public function test_a_decision_that_is_not_plain_text_is_refused()
    {
        $this->expectException(ConstructionFailed::class);

        $this->planned([['text' => 'Phone numbers are optional', 'touches' => [], 'reversible' => true]]);
    }

    /**
     * A plan as saved, with the decisions already tagged.
     *
     * @param  list<array<string, mixed>>  $assumptions
     * @param  array<string, mixed>|null  $question
     */
    protected function plan(array $assumptions, ?array $question = null): Plan
    {
        $saved = $this->planned([], $question)->toArray();

        return Plan::fromArray([...$saved, 'assumptions' => $assumptions]);
    }

    /**
     * A plan as the planner returns it.
     *
     * @param  list<mixed>  $assumptions
     * @param  array<string, mixed>|null  $question
     */
    protected function planned(array $assumptions, ?array $question = null): Plan
    {
        return Plan::fromModelOutput([
            'summary' => 'Book rooms.',
            'acceptance_criteria' => ['Members can book a room.'],
            'cases' => [['base' => 'A member books a free room.', 'alternate' => null, 'no_alternate' => 'Rooms are only booked one way.', 'exception' => null, 'no_exception' => 'Nothing is refused.']],
            'assumptions' => $assumptions,
            'tasks' => ['Add bookings.'],
            'steps' => [['key' => 'booking', 'kind' => 'data', 'label' => 'Bookings', 'file' => 'app/Models/Booking.php', 'symbol' => 'Booking', 'detail' => 'Holds a booking.']],
            'question' => $question,
        ], []);
    }
}
