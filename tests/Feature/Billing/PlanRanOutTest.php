<?php

namespace Tests\Feature\Billing;

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlanRanOutTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected FeatureRequest $featureRequest;

    protected function setUp(): void
    {
        parent::setUp();

        config(['billing.plans.free.monthly_usd' => 5, 'operations.operators' => []]);
        $this->owner = User::factory()->create();
        $project = Project::factory()->for($this->owner, 'owner')->create();
        $this->featureRequest = FeatureRequest::factory()->for($project)->for($this->owner, 'user')->create();
        Run::factory()->for($this->featureRequest)->create([
            'status' => RunStatus::Failed,
            'stop_reason' => 'usage_limit',
            'error' => 'You have used all the AI use your plan includes this month.',
        ])->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => 5.5]);
    }

    public function test_a_change_stopped_by_the_plan_offers_the_way_to_the_plan()
    {
        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $this->featureRequest))
            ->assertInertia(fn (Assert $page) => $page->where('run.plan_ran_out', true));
    }

    public function test_the_way_to_the_plan_goes_once_the_use_starts_again()
    {
        $this->travel(1)->months();

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $this->featureRequest))
            ->assertInertia(fn (Assert $page) => $page->where('run.plan_ran_out', false));
    }

    public function test_a_change_stopped_for_another_reason_offers_no_way_to_the_plan()
    {
        $this->featureRequest->latestRun->update(['stop_reason' => 'budget_exhausted']);

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $this->featureRequest))
            ->assertInertia(fn (Assert $page) => $page->where('run.plan_ran_out', false));
    }
}
