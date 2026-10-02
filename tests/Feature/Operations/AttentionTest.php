<?php

namespace Tests\Feature\Operations;

use App\Actions\Operations\FindAttentionItems;
use App\Actions\Operations\SummarizeSpend;
use App\Actions\Runners\ScaleRunnerPool;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Decision;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\PreviewRebuild;
use App\Models\Project;
use App\Models\Run;
use App\Models\Runner;
use App\Models\User;
use App\Models\Verification;
use App\Models\VisualEdit;
use App\Models\WorkerHeartbeat;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.workers.queues' => ['default', 'previews']]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function attention(): array
    {
        return app(FindAttentionItems::class)->handle(7);
    }

    /**
     * @param  array<string, mixed>  $attention
     * @return array<string, mixed>|null
     */
    protected function item(array $attention, string $key): ?array
    {
        return collect($attention['items'])->firstWhere('key', $key);
    }

    public function test_a_queue_is_healthy_only_when_a_live_worker_says_so()
    {
        WorkerHeartbeat::query()->create(['worker' => 'host:1', 'connection' => 'redis', 'queues' => 'default', 'last_seen_at' => now()]);

        $queues = collect($this->attention()['workers'])->keyBy('queue');

        $this->assertTrue($queues['default']['heard_from']);
        $this->assertSame(1, $queues['default']['alive']);
        $this->assertFalse($queues['default']['attention']);

        // No worker has ever reported for previews, so it is not called healthy.
        $this->assertFalse($queues['previews']['heard_from']);
        $this->assertSame(0, $queues['previews']['alive']);
        $this->assertTrue($queues['previews']['attention']);
    }

    public function test_runs_with_no_progress_and_runs_no_worker_holds_are_listed()
    {
        $this->travelTo(now()->subHour());
        $stuck = Run::factory()->create(['status' => RunStatus::Planning, 'lease_expires_at' => now()->addHours(2)]);
        $stuck->recordEvent('status', ['from' => 'queued', 'to' => 'planning']);
        $unleased = Run::factory()->create(['status' => RunStatus::Implementing, 'lease_expires_at' => now()->addMinutes(5)]);
        $waiting = Run::factory()->create(['status' => RunStatus::NeedsUserDecision]);
        $this->travelBack();
        $unleased->recordEvent('progress');

        $attention = $this->attention();
        $stuckItem = $this->item($attention, 'stuck_runs');
        $leaseItem = $this->item($attention, 'expired_leases');

        $this->assertSame(1, $stuckItem['count'] ?? null);
        $this->assertSame("Run {$stuck->id}", $stuckItem['records'][0]['label']);
        $this->assertSame(route('operations.changes.show', $stuck->feature_request_id), $stuckItem['records'][0]['href']);
        $this->assertSame(1, $leaseItem['count'] ?? null);
        $this->assertSame("Run {$unleased->id}", $leaseItem['records'][0]['label']);
        $this->assertSame(1, $attention['waiting_on_owner']);
        $this->assertNotContains("Run {$waiting->id}", collect($stuckItem['records'])->pluck('label'));
    }

    public function test_failures_are_grouped_by_stage_and_reason_and_questions_are_not_failures()
    {
        foreach (['budget_exhausted', 'budget_exhausted', 'question'] as $reason) {
            Run::factory()->create(['status' => RunStatus::NeedsUserDecision])
                ->recordEvent('status', ['from' => 'implementing', 'to' => 'needs_user_decision', 'reason' => $reason]);
        }
        Run::factory()->create(['status' => RunStatus::Failed])->recordEvent('status', ['from' => 'planning', 'to' => 'failed']);
        Verification::factory()->create(['status' => VerificationStatus::Errored, 'finished_at' => now()]);
        Preview::factory()->create(['status' => PreviewStatus::Failed, 'error' => 'Port in use']);

        $attention = $this->attention();
        $failures = collect($attention['failures'])->keyBy(fn (array $group) => "{$group['stage']}:{$group['reason']}");

        $this->assertSame(2, $failures['implementing:budget_exhausted']['count']);
        $this->assertSame(route('operations.changes.index', ['reason' => 'budget_exhausted']), $failures['implementing:budget_exhausted']['href']);
        $this->assertSame(1, $failures['planning:unknown']['count']);
        $this->assertSame(1, $failures['verification:checks_errored']['count']);
        $this->assertSame(1, $failures['preview:start_failed']['count']);
        $this->assertFalse($failures->has('implementing:question'));
        $this->assertSame(2, $this->item($attention, 'budgets_exhausted')['count'] ?? null);
        $this->assertSame('Port in use', $this->item($attention, 'preview_start_failed')['records'][0]['detail'] ?? null);
    }

    public function test_workspaces_that_were_not_cleaned_up_are_listed()
    {
        $user = User::factory()->create();
        $this->travelTo(now()->subHours(6));
        $orphan = Workspace::factory()->create(['user_id' => $user->id, 'last_activity_at' => now(), 'expires_at' => now()->addDays(2)]);
        $inUse = Workspace::factory()->create(['user_id' => $user->id, 'last_activity_at' => now(), 'expires_at' => now()->addDays(2)]);
        Run::factory()->create(['status' => RunStatus::Implementing, 'workspace_id' => $inUse->id]);
        $overdue = Workspace::factory()->create(['user_id' => $user->id, 'last_activity_at' => now(), 'expires_at' => now()->addHour()]);
        $this->travelBack();
        $failed = Workspace::factory()->create(['user_id' => $user->id, 'cleanup_failed_at' => now(), 'cleanup_error' => 'box service unreachable']);
        Workspace::factory()->create(['user_id' => $user->id, 'status' => WorkspaceStatus::Destroyed, 'cleanup_failed_at' => now()->subDay()]);

        $attention = $this->attention();
        $labels = fn (string $key) => collect($this->item($attention, $key)['records'] ?? [])->pluck('label')->all();

        $this->assertContains("Workspace {$orphan->id} (fake)", $labels('workspace_orphaned'));
        $this->assertNotContains("Workspace {$inUse->id} (fake)", $labels('workspace_orphaned'));
        $this->assertContains("Workspace {$overdue->id} (fake)", $labels('workspace_cleanup_overdue'));
        $this->assertSame(["Workspace {$failed->id} (fake)"], $labels('workspace_cleanup_failed'));
    }

    public function test_runner_machines_that_need_the_operator_are_listed()
    {
        Runner::factory()->create(['name' => 'quiet', 'last_seen_at' => now()->subMinutes(10)]);
        Runner::factory()->create(['name' => 'draining', 'last_seen_at' => now()->subMinutes(10), 'draining_at' => now()]);
        Runner::factory()->create(['name' => 'starting', 'last_seen_at' => null]);
        Runner::factory()->create(['name' => 'full', 'last_seen_at' => now(), 'disk_free_mb' => 100]);
        Runner::factory()->create(['name' => 'fine', 'last_seen_at' => now(), 'disk_free_mb' => 50000]);
        // As Redis gives it back: a string.
        Cache::put(ScaleRunnerPool::PAUSED_UNTIL, (string) now()->addMinutes(20)->getTimestamp());
        Cache::put(ScaleRunnerPool::PAUSED_BECAUSE, 'The cloud would not start a machine: server limit reached');

        $attention = $this->attention();
        $labels = fn (string $key) => collect($this->item($attention, $key)['records'] ?? [])->pluck('label')->all();

        $this->assertSame(['Runner quiet'], $labels('runners_quiet'));
        $this->assertStringContainsString('runners:remove --gone', $this->item($attention, 'runners_quiet')['records'][0]['detail']);
        $this->assertSame(['Runner full'], $labels('runners_full'));
        $this->assertSame(1, $this->item($attention, 'machine_starts_paused')['count']);
        $this->assertSame('The cloud would not start a machine: server limit reached', $this->item($attention, 'machine_starts_paused')['records'][0]['detail']);

        Cache::forget(ScaleRunnerPool::PAUSED_UNTIL);
        $this->assertNull($this->item($this->attention(), 'machine_starts_paused'));
    }

    public function test_it_measures_the_time_from_saving_an_edit_to_the_preview_showing_it()
    {
        $project = Project::factory()->create();
        $preview = Preview::factory()->create(['project_id' => $project->id, 'feature_request_id' => null, 'status' => PreviewStatus::Ready]);
        $rebuild = fn (string $status, string $start, ?string $end) => PreviewRebuild::query()->create([
            'preview_id' => $preview->id, 'project_id' => $project->id, 'status' => $status, 'from_revision' => str_repeat('a', 40), 'to_revision' => str_repeat('b', 40),
            'queued_at' => now()->modify($start), 'started_at' => now()->modify($start), 'finished_at' => $end === null ? null : now()->modify($end),
        ]);

        VisualEdit::factory()->create(['project_id' => $project->id, 'created_at' => now()->subSeconds(100)]);
        $rebuild('failed', '-99 seconds', '-95 seconds');
        $rebuild('rebuilt', '-94 seconds', '-91 seconds');
        VisualEdit::factory()->create(['project_id' => $project->id, 'created_at' => now()->subSeconds(60)]);
        $rebuild('rebuilt', '-58 seconds', '-20 seconds');
        // Saved with nothing rebuilt since.
        VisualEdit::factory()->create(['project_id' => $project->id, 'created_at' => now()->subSeconds(5)]);

        $attention = $this->attention();

        $this->assertSame(['edits' => 3, 'measured' => 2, 'median_seconds' => 40.0, 'p90_seconds' => 40.0, 'max_seconds' => 40.0, 'slow' => 1, 'not_seen' => 1], $attention['rebuilds']);
        $this->assertSame(1, $this->item($attention, 'preview_rebuild_failed')['count'] ?? null);
    }

    public function test_spend_keeps_reported_estimated_and_unknown_costs_apart()
    {
        $run = Run::factory()->create();
        $run->recordEvent('model_call', ['provider' => 'anthropic', 'model' => 'a', 'input_tokens' => 100, 'output_tokens' => 10, 'cost_usd' => 0.5, 'cost_source' => 'reported']);
        $run->recordEvent('model_call', ['provider' => 'openai', 'model' => 'b', 'input_tokens' => 200, 'output_tokens' => 20, 'cost_usd' => 0.25, 'cost_source' => 'estimated']);
        $run->recordEvent('model_call', ['provider' => 'openai', 'model' => 'c', 'input_tokens' => 300, 'output_tokens' => 30, 'cost_usd' => null]);
        // Recorded before calls carried a source: the coding agent's were reported.
        $run->recordEvent('model_call', ['adapter' => 'claude', 'model' => 'd', 'cost_usd' => 1.0]);
        Project::factory()->create(['setup_model_calls' => [
            ['model' => 'a', 'cost_usd' => 0.1, 'cost_source' => 'estimated', 'at' => now()->toIso8601String()],
            ['model' => 'a', 'cost_usd' => 0.1],
        ]]);

        $spend = app(SummarizeSpend::class)->handle(now()->subDay()->toImmutable());

        $this->assertSame(5, $spend['calls']);
        $this->assertSame(1, $spend['unpriced_calls']);
        $this->assertSame(1.5, $spend['reported_usd']);
        $this->assertSame(0.35, $spend['estimated_usd']);
        $this->assertSame(1.85, $spend['total_usd']);
        $this->assertSame(600, $spend['input_tokens']);
        $this->assertSame(1, $spend['undated_setup_calls']);
        $this->assertSame('partial', $spend['completeness']);
    }

    public function test_spend_is_complete_only_when_every_call_is_priced_and_metered()
    {
        $since = now()->subDay()->toImmutable();
        $this->assertSame('none', app(SummarizeSpend::class)->handle($since)['completeness']);

        Run::factory()->create()->recordEvent('model_call', ['model' => 'a', 'cost_usd' => 0.5, 'cost_source' => 'reported']);
        $this->assertSame('complete', app(SummarizeSpend::class)->handle($since)['completeness']);

        // A metered decision call with a price keeps the total complete.
        config(['builder.prices' => ['decider' => ['input' => 1, 'output' => 1]]]);
        $metered = FeatureRequest::factory()->create(['decision_model_calls' => [
            ['provider' => 'typesafe', 'model' => 'decider', 'input_tokens' => 500_000, 'output_tokens' => 0, 'cost_usd' => 0.5, 'cost_source' => 'estimated', 'at' => now()->toIso8601String()],
        ]]);
        Decision::factory()->create(['feature_request_id' => $metered->id, 'model' => 'decider']);
        $spend = app(SummarizeSpend::class)->handle($since);
        $this->assertSame([2, 1, 1.0, 'complete'], [$spend['calls'], $spend['decision_calls'], $spend['total_usd'], $spend['completeness']]);

        // Requests decided before decision calls were metered make it partial.
        Decision::factory()->create(['feature_request_id' => FeatureRequest::factory(), 'model' => 'decider']);
        $spend = app(SummarizeSpend::class)->handle($since);
        $this->assertSame(1, $spend['unmetered_decision_calls']);
        $this->assertSame('partial', $spend['completeness']);
    }

    public function test_the_page_renders_for_an_operator()
    {
        config(['operations.operators' => ['ops@example.com']]);
        Run::factory()->create(['status' => RunStatus::Failed])->recordEvent('status', ['from' => 'planning', 'to' => 'failed', 'reason' => 'cannot_generate']);

        $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))
            ->get(route('operations.attention', ['days' => 30]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/Attention')
                ->where('attention.days', 30)
                ->where('attention.failures.0.reason', 'cannot_generate')
                ->has('attention.workers', 2));
    }
}
