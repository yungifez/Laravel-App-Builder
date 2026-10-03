<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ChoosePlan;
use App\Actions\Billing\MeasureUsage;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashier.secret' => null,
            'billing.plans.free.monthly_usd' => 5,
            'billing.plans.pro.monthly_usd' => 15,
            'billing.plans.pro.stripe_price' => null,
            'billing.plans.max.monthly_usd' => 70,
            'billing.plans.max.stripe_price' => null,
            'operations.operators' => [],
        ]);
    }

    public function test_a_visitor_sees_the_plans_and_paid_plans_wait_for_stripe()
    {
        $this->get(route('pricing'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('public/Pricing')
                ->where('currentPlan', null)
                ->where('plans.0', ['key' => 'free', 'name' => 'Free', 'price' => 0, 'times' => 1, 'open' => true])
                ->where('plans.1.times', 3)
                ->where('plans.1.open', false)
                ->where('plans.2.times', 14));
    }

    public function test_a_paid_plan_opens_once_stripe_and_its_price_are_set()
    {
        config(['cashier.secret' => 'sk_test_x', 'billing.plans.pro.stripe_price' => 'price_pro']);

        $this->get(route('pricing'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('plans.1.open', true)
                ->where('plans.2.open', false));
    }

    public function test_an_owner_sees_how_much_of_this_months_use_is_left()
    {
        $this->travelTo(now()->setDate(2026, 10, 10));
        $owner = User::factory()->create(['created_at' => now()->setDate(2026, 8, 4)]);
        $this->spend($owner, 2);
        $this->spend(User::factory()->create(), 40);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Billing')
                ->where('currentPlan', 'free')
                ->where('usage', ['percent' => 40, 'resetsOn' => '4 November', 'unlimited' => false])
                ->where('canManage', false));
    }

    public function test_use_from_before_this_month_does_not_count()
    {
        $owner = User::factory()->create(['created_at' => now()->subMonths(2)]);
        $this->travel(-35)->days();
        $this->spend($owner, 40);
        $this->travelBack();

        $usage = app(MeasureUsage::class)->handle($owner);

        $this->assertSame(0, $usage['percent']);
        $this->assertFalse($usage['reached']);
    }

    public function test_an_operators_use_has_no_limit()
    {
        $owner = User::factory()->create(['email' => 'ops@example.com']);
        config(['operations.operators' => ['ops@example.com']]);
        $this->spend($owner, 40);

        $usage = app(MeasureUsage::class)->handle($owner);

        $this->assertTrue($usage['unlimited']);
        $this->assertFalse($usage['reached']);
    }

    public function test_a_plan_that_is_not_open_cannot_be_chosen()
    {
        $this->mock(ChoosePlan::class, fn (MockInterface $mock) => $mock->shouldNotReceive('handle'));

        $this->actingAs(User::factory()->create())
            ->post(route('billing.plan.store'), ['plan' => 'pro'])
            ->assertSessionHasErrors(['plan' => 'That plan is not open yet.']);
    }

    public function test_choosing_an_open_plan_sends_the_owner_to_stripe_checkout()
    {
        config(['cashier.secret' => 'sk_test_x', 'billing.plans.pro.stripe_price' => 'price_pro']);
        $owner = User::factory()->create();
        $this->mock(ChoosePlan::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')
            ->once()
            ->withArgs(fn (User $user, string $price) => $user->is($owner) && $price === 'price_pro')
            ->andReturn('https://checkout.stripe.com/c/pay/cs_test'));

        $this->actingAs($owner)
            ->post(route('billing.plan.store'), ['plan' => 'pro'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.com/c/pay/cs_test');
    }

    public function test_a_subscriber_who_swaps_plans_comes_back_to_their_plan()
    {
        config(['cashier.secret' => 'sk_test_x', 'billing.plans.max.stripe_price' => 'price_max']);
        $this->mock(ChoosePlan::class, fn (MockInterface $mock) => $mock->shouldReceive('handle')->once()->andReturn(null));

        $this->actingAs(User::factory()->create())
            ->post(route('billing.plan.store'), ['plan' => 'max'])
            ->assertRedirect(route('billing.edit'));
    }

    public function test_only_a_stripe_customer_can_open_the_billing_portal()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('billing.portal'))
            ->assertNotFound();
    }

    public function test_guests_cannot_see_billing()
    {
        $this->get(route('billing.edit'))->assertRedirect(route('login'));
    }

    /**
     * Record an AI call for one of the owner's apps.
     */
    protected function spend(User $owner, float $usd): void
    {
        $project = Project::factory()->for($owner, 'owner')->create();
        Run::factory()->for(FeatureRequest::factory()->for($project))->create()
            ->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => $usd]);
    }

    public function test_an_owner_sees_a_plan_we_gave_them_and_when_it_ends()
    {
        $owner = User::factory()->create(['granted_plan' => 'pro', 'granted_plan_until' => now()->addDays(10)]);

        $this->actingAs($owner)
            ->get(route('billing.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentPlan', 'pro')
                ->where('given.until', now()->addDays(10)->isoFormat('D MMMM YYYY')));

        $this->actingAs(User::factory()->create())
            ->get(route('billing.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('given', null));
    }
}
