<?php

namespace Tests\Feature\Billing;

use App\Actions\Operations\FindAttentionItems;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\DecideFeatureRequest;
use App\Jobs\ExecuteRun;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Workspace;
use App\Runs\Exceptions\ProvidersUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * Every way a change stops on money or limits ends in a next step that
 * works: keep trying, try again at a stated time, move to a bigger plan,
 * or try again once our account has credit.
 */
class BudgetNextStepTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const SMALLER_PART = 'This is our fault: this change needed more work than I can do in one go, so I stopped. Nothing in your app changed. Try again, or ask for a smaller part first.';

    protected const KEEP_TRYING = 'This is our fault: this change needed more work than I can do in one go, so I stopped. Nothing in your app changed. Keep trying to go on from where I stopped, or ask for a smaller part first.';

    protected const OUT_OF_CREDIT = 'This is our fault. Our account with the AI service is out of credit. We have been told. Try again later.';

    protected User $owner;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => [], 'billing.plans.free.monthly_usd' => 5, 'builder.construction.budgets.daily_usd' => 10]);
        $this->owner = User::factory()->create();
        $this->project = Project::factory()->for($this->owner, 'owner')->create();
    }

    public function test_a_change_that_used_its_try_keeps_trying_from_its_work_so_far(): void
    {
        Queue::fake([ExecuteRun::class, VerifyFeatureRequest::class]);
        $change = $this->stopped('budget_exhausted', 'This change used all the AI work one try may take. Your app is as it was. You can ask it to keep trying.');

        $this->assertStop($change, self::KEEP_TRYING, canRetry: true);

        $this->post(route('feature-requests.keep-trying.store', $change))->assertSessionHasNoErrors();

        $this->assertSame(RunStatus::Implementing, $change->latestRun->refresh()->status);
        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_a_change_that_used_its_try_with_its_work_gone_is_tried_again(): void
    {
        Queue::fake([ExecuteRun::class, DecideFeatureRequest::class]);
        $change = $this->stopped('budget_exhausted', 'This change used all the AI work one try may take. Your app is as it was. You can ask it to keep trying.');
        $change->latestRun->workspace->update(['status' => WorkspaceStatus::Destroyed]);

        // The words promise only what the page offers.
        $this->assertStop($change, self::SMALLER_PART, canRetry: true);

        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasNoErrors();
        $this->assertSame(1, FeatureRequest::query()->where('retry_of_id', $change->id)->count());
    }

    public function test_a_change_out_of_tool_time_says_to_try_again_or_ask_for_less(): void
    {
        $change = $this->stopped('budget_exhausted', 'The run used all 12 minutes of its time.', workspace: false);

        $this->assertStop($change, self::SMALLER_PART, canRetry: true);
    }

    public function test_the_daily_pause_says_when_it_lifts_in_the_owners_time(): void
    {
        $this->travelTo('2026-10-05 23:59:00');
        $this->spendToday(10.5);
        $change = $this->stopped('spend_limit', 'This is our fault: we paused new work for today to keep our costs in check. Nothing in your app changed. Try again tomorrow.', status: RunStatus::Failed);

        $this->assertStop($change, 'This is our fault: we paused new work to keep our costs in check. Nothing in your app changed. You can try again from 12:00 AM tomorrow.', canRetry: false);

        // In New York the day has hours left, so it lifts today.
        $this->withUnencryptedCookie('time_zone', 'America/New_York')
            ->get(route('feature-requests.show', $change))
            ->assertInertia(fn (Assert $page) => $page->where('run.error', 'This is our fault: we paused new work to keep our costs in check. Nothing in your app changed. You can try again from 8:00 PM today.'));

        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasErrors('retry');
    }

    public function test_once_the_daily_pause_lifts_the_change_is_tried_again(): void
    {
        Queue::fake([ExecuteRun::class, DecideFeatureRequest::class]);
        $this->travelTo('2026-10-05 23:59:00');
        $this->spendToday(10.5);
        $change = $this->stopped('spend_limit', 'This is our fault: we paused new work for today to keep our costs in check. Nothing in your app changed. Try again tomorrow.', status: RunStatus::Failed);

        $this->travelTo('2026-10-06 00:00:01');

        $this->assertStop($change, 'This is our fault: we paused new work for a day to keep our costs in check. That pause is over, so you can try again now. Nothing in your app changed.', canRetry: true);
        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasNoErrors();
        $this->assertSame(1, FeatureRequest::query()->where('retry_of_id', $change->id)->count());
    }

    public function test_no_new_ask_is_made_while_new_work_is_paused(): void
    {
        Queue::fake();
        $this->travelTo('2026-10-05 23:59:00');
        $this->spendToday(10.5);

        $this->ask()->assertSessionHasErrors(['prompt' => 'This is our fault: we paused new work to keep our costs in check. Nothing in your app changed. You can try again from 12:00 AM tomorrow.']);

        $this->assertSame(0, $this->project->featureRequests()->count());
    }

    public function test_no_new_ask_is_made_once_the_plan_is_used_up(): void
    {
        Queue::fake();
        $this->usePlan(5.5);

        $this->ask()->assertSessionHasErrors('prompt');

        // Only the ask that used the plan is there.
        $this->assertSame(1, $this->project->featureRequests()->count());
    }

    public function test_a_bigger_plan_lets_the_owner_ask_and_try_again(): void
    {
        Queue::fake();
        $change = $this->usePlan(5.5);
        config(['billing.plans.pro.stripe_price' => 'price_pro']);

        $this->assertStop($change, null, canRetry: false);

        $this->owner->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_test', 'stripe_status' => 'active', 'stripe_price' => 'price_pro', 'quantity' => 1]);

        $this->assertStop($change, 'This stopped because your plan\'s AI use for the month ran out. It has started again, so you can try again now. Nothing in your app changed.', canRetry: true);
        $this->post(route('feature-requests.retries.store', $change))->assertSessionHasNoErrors();
        $this->ask()->assertSessionHasNoErrors();
        $this->assertSame(3, $this->project->featureRequests()->count());
    }

    public function test_our_account_out_of_credit_is_on_the_attention_list_and_the_owner_is_told_so(): void
    {
        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();
        config([
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);
        FeaturePlanner::fake(fn () => throw InsufficientCreditsException::forProvider('anthropic'));
        $change = FeatureRequest::factory()->for(Project::factory()->for($this->owner, 'owner')->create(['source_path' => $this->makeProjectSource()]))->create();

        $run = app(StartRun::class)->handle($change)->refresh();

        $this->assertSame('out_of_credit', $run->stop_reason);
        $this->assertStop($change, self::OUT_OF_CREDIT, canRetry: true);
        $this->assertSame(["Run {$run->id}"], array_column($this->attention('ai_out_of_credit')['records'] ?? [], 'label'));
    }

    public function test_a_busy_ai_service_is_not_called_out_of_credit(): void
    {
        $busy = ProvidersUnavailable::because(RateLimitedException::forProvider('anthropic', 429));
        $empty = ProvidersUnavailable::because(InsufficientCreditsException::forProvider('anthropic'));

        $this->assertSame('providers_unavailable', $busy->reason());
        $this->assertStringEndsWith('Try again in a few minutes.', $busy->getMessage());
        // When credit comes back is not known, so no wait is promised.
        $this->assertSame('out_of_credit', $empty->reason());
        $this->assertStringEndsWith('Try again later.', $empty->getMessage());
        $this->assertTrue(ProvidersUnavailable::saysOutOfCredit('billing_error', null));
        $this->assertTrue(ProvidersUnavailable::saysOutOfCredit(null, 'Your credit balance is too low.'));
        $this->assertFalse(ProvidersUnavailable::saysOutOfCredit('rate_limit', 'The provider reported rate_limit.'));

        $change = $this->stopped('providers_unavailable', $busy->getMessage(), workspace: false);

        $this->assertStop($change, $busy->getMessage(), canRetry: true);
        $this->assertNull($this->attention('ai_out_of_credit'));
    }

    /**
     * Make a change whose run stopped for the given reason.
     */
    protected function stopped(string $reason, string $error, RunStatus $status = RunStatus::NeedsUserDecision, bool $workspace = true): FeatureRequest
    {
        $change = FeatureRequest::factory()->for($this->project)->for($this->owner, 'user')->create([
            'status' => $status === RunStatus::Failed ? FeatureRequestStatus::Failed : FeatureRequestStatus::Generating,
        ]);
        $run = Run::factory()->for($change)->create([
            'status' => $status,
            'stop_reason' => $reason,
            'error' => $error,
            'plan' => ['summary' => 'Teams get a description.', 'acceptance_criteria' => [], 'cases' => [], 'written_tests' => [], 'written_files' => [], 'assumptions' => [], 'tasks' => [], 'steps' => [], 'acceptance' => [], 'solution_key' => null],
            'workspace_id' => $workspace ? Workspace::factory()->create()->id : null,
        ]);
        $run->recordEvent('status', ['from' => 'implementing', 'to' => $status->value, 'reason' => $reason]);

        return $change;
    }

    /**
     * Spend the owner's plan on one change, which stopped because of it.
     */
    protected function usePlan(float $usd): FeatureRequest
    {
        $change = $this->stopped('usage_limit', 'You have used all the AI use your plan includes this month. It starts again on 1 November, or you can move to a bigger plan in Settings. Nothing in your app changed.', status: RunStatus::Failed);
        $change->latestRun->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => $usd]);

        return $change;
    }

    protected function spendToday(float $usd): void
    {
        Run::factory()->create()->recordEvent('model_call', ['role' => 'coder', 'adapter' => 'codex', 'cost_usd' => $usd]);
    }

    /**
     * @return TestResponse<Response>
     */
    protected function ask(): TestResponse
    {
        return $this->actingAs($this->owner)->post(route('feature-requests.store', $this->project), ['prompt' => 'Show plan prices in euros']);
    }

    protected function assertStop(FeatureRequest $change, ?string $error, bool $canRetry): void
    {
        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $change))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.can_retry', $canRetry)
                ->when($error !== null, fn (Assert $page) => $page->where('run.error', $error))
                ->etc());
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function attention(string $key): ?array
    {
        return collect(app(FindAttentionItems::class)->handle(7)['items'])->firstWhere('key', $key);
    }
}
