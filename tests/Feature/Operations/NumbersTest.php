<?php

namespace Tests\Feature\Operations;

use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NumbersTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'operations.operators' => ['ops@example.com'],
            'billing.plans.pro.price' => 25,
            'billing.plans.pro.stripe_price' => 'price_pro',
            'billing.plans.max.price' => 100,
            'billing.plans.max.stripe_price' => 'price_max',
        ]);
        $this->operator = User::factory()->create(['email' => 'ops@example.com', 'created_at' => now()->subYear()]);
    }

    public function test_only_operators_see_the_numbers()
    {
        $this->actingAs(User::factory()->create())->get(route('operations.numbers'))->assertForbidden();
    }

    public function test_the_numbers_count_money_people_and_days()
    {
        $this->subscribe(User::factory()->create(), 'price_pro');
        $this->subscribe(User::factory()->create(), 'price_max');
        $this->subscribe(User::factory()->create(), 'price_max', 'canceled');
        User::factory()->create(['granted_plan' => 'pro']);
        User::factory()->unverified()->create(['created_at' => now()->subDays(60)]);

        $builder = User::factory()->create();
        $request = FeatureRequest::factory()->for(Project::factory()->for($builder, 'owner'))->create();
        Run::factory()->for($request)->create()->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 3]);

        $this->actingAs($this->operator)
            ->get(route('operations.numbers'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/Numbers')
                ->where('days', 30)
                ->where('revenue.monthly_usd', 125)
                ->where('revenue.plans.0.paying', 1)
                ->where('revenue.plans.0.given', 1)
                ->where('revenue.plans.1.paying', 1)
                ->where('people.total', 7)
                ->where('people.joined', 5)
                ->where('people.building', 1)
                ->where('spend.total_usd', 3)
                ->has('daily', 30)
                ->where('daily.29.joined', 5)
                ->where('daily.29.changes', 1));

        $this->get(route('operations.numbers', ['days' => 7]))
            ->assertInertia(fn (Assert $page) => $page->where('days', 7)->has('daily', 7));
        $this->get(route('operations.numbers', ['days' => 3]))
            ->assertInertia(fn (Assert $page) => $page->where('days', 30));
    }

    protected function subscribe(User $user, string $price, string $status = 'active'): void
    {
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.Str::random(10),
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
        ]);

        $subscription->items()->create([
            'stripe_id' => 'si_'.Str::random(10),
            'stripe_product' => 'prod_'.Str::random(6),
            'stripe_price' => $price,
            'quantity' => 1,
        ]);
    }
}
