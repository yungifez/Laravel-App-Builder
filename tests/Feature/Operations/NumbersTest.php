<?php

namespace Tests\Feature\Operations;

use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\VisualEdit;
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

    public function test_the_numbers_answer_how_changes_went_across_every_app()
    {
        // A kept change that passed first time and touched only what it was about.
        $kept = $this->change(['commit_sha' => 'abc', 'accepted_at' => now()], cost: 2.0, unexpected: [], first: VerificationStatus::Passed);
        // Another app's change, abandoned after it failed and touched billing.
        $this->change([], cost: 1.0, unexpected: ['billing' => ['app/Billing.php']], first: VerificationStatus::Failed);
        $kept->update(['decision_model_calls' => [['provider' => 'anthropic', 'model' => 'm', 'input_tokens' => 1, 'output_tokens' => 1, 'cost_usd' => null, 'cost_source' => null, 'at' => now()->toIso8601String()]]]);
        VisualEdit::factory()->count(2)->create();

        $this->actingAs($this->operator)
            ->get(route('operations.numbers'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.kept', 1)
                ->where('changes.cost_per_kept_change_usd', 3)
                ->where('changes.unpriced_calls', 1)
                ->where('changes.runs_verified', 2)
                ->where('changes.first_attempt_passed', 1)
                ->where('changes.reviewed', 2)
                ->where('changes.with_unexpected_changes', 1)
                ->where('changes.edits_without_model', 2));
    }

    public function test_with_nothing_kept_there_is_no_cost_per_kept_change()
    {
        $this->change([], cost: 1.0, unexpected: [], first: VerificationStatus::Failed);

        $this->actingAs($this->operator)
            ->get(route('operations.numbers'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.kept', 0)
                ->where('changes.cost_per_kept_change_usd', null)
                ->where('changes.edits_without_model', 0));
    }

    public function test_changes_and_edits_before_the_window_are_left_out()
    {
        $this->change(['commit_sha' => 'old', 'accepted_at' => now()->subDays(40), 'created_at' => now()->subDays(40)], cost: 5.0, unexpected: [], first: VerificationStatus::Passed);
        VisualEdit::factory()->create(['created_at' => now()->subDays(40)]);
        $this->change(['commit_sha' => 'new', 'accepted_at' => now()], cost: 1.0, unexpected: [], first: VerificationStatus::Passed);

        $this->actingAs($this->operator)
            ->get(route('operations.numbers'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.kept', 1)
                ->where('changes.cost_per_kept_change_usd', 1)
                ->where('changes.runs_verified', 1)
                ->where('changes.edits_without_model', 0));

        // A wider window takes the older change in.
        $this->get(route('operations.numbers', ['days' => 90]))
            ->assertInertia(fn (Assert $page) => $page->where('changes.kept', 2)->where('changes.edits_without_model', 1));
    }

    /**
     * Make a reviewed change in its own app, with one model call and its
     * first check.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<string>>  $unexpected
     */
    protected function change(array $attributes, float $cost, array $unexpected, VerificationStatus $first): FeatureRequest
    {
        $request = FeatureRequest::factory()->generated()->create($attributes);
        $run = Run::factory()->for($request)->create([
            'status' => RunStatus::Completed,
            'review' => ['approved' => true, 'summary' => 'ok', 'preserved' => [], 'verified' => [], 'coverage' => [], 'findings' => [], 'changes' => [], 'classification' => [
                'requested' => [], 'may_also_affect' => [], 'unexpected' => $unexpected, 'unclaimed' => [], 'context_updates' => [], 'targets' => [], 'notes_behind' => [], 'observed' => null,
            ]],
        ]);
        $run->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'claude', 'input_tokens' => 10, 'output_tokens' => 1, 'cost_usd' => $cost]);
        $request->verifications()->create(['run_id' => $run->id, 'status' => $first]);

        return $request;
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
