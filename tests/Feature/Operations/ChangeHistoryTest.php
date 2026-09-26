<?php

namespace Tests\Feature\Operations;

use App\Actions\Operations\DescribeChangeHistory;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use App\Models\Verification;
use App\Operations\ChangeOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChangeHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.operators' => ['ops@example.com']]);
        $this->operator = User::factory()->create(['email' => 'ops@example.com']);
    }

    /**
     * One request for each outcome, keyed by the outcome it should have.
     *
     * @return array<string, FeatureRequest>
     */
    protected function oneOfEach(): array
    {
        $request = fn (array $attributes = [], ?RunStatus $run = null) => tap(
            FeatureRequest::factory()->generated()->create($attributes),
            fn (FeatureRequest $change) => $run === null ? null : Run::factory()->create(['feature_request_id' => $change->id, 'status' => $run]),
        );

        return [
            'reverted' => $request(['commit_sha' => str_repeat('c', 40), 'accepted_at' => now(), 'revert_sha' => str_repeat('d', 40), 'reverted_at' => now()], RunStatus::Completed),
            'kept' => $request(['commit_sha' => str_repeat('c', 40), 'accepted_at' => now()], RunStatus::Completed),
            'answered' => $request(['status' => FeatureRequestStatus::Answered]),
            'cancelled' => $request([], RunStatus::Cancelled),
            'failed' => $request([], RunStatus::Failed),
            'waiting_on_owner' => $request([], RunStatus::NeedsUserDecision),
            'in_progress' => $request([], RunStatus::Verifying),
            'built' => $request([], RunStatus::Completed),
        ];
    }

    public function test_every_change_has_exactly_one_outcome_and_the_list_filter_agrees()
    {
        $changes = $this->oneOfEach();

        // A failed first try that was retried counts by its latest try.
        $retried = $changes['built'];
        Run::factory()->create(['feature_request_id' => $retried->id, 'status' => RunStatus::Failed]);
        Run::factory()->create(['feature_request_id' => $retried->id, 'status' => RunStatus::Completed]);

        foreach ($changes as $expected => $change) {
            $this->assertSame($expected, ChangeOutcome::of($change->fresh(['latestRun']) ?? $change)->value, "Change for {$expected}");
        }

        foreach (ChangeOutcome::cases() as $outcome) {
            $query = FeatureRequest::query();
            $outcome->scope($query);

            $this->assertSame([$changes[$outcome->value]->id], $query->pluck('id')->all(), "Scope for {$outcome->value}");
        }
    }

    public function test_unverified_checks_are_never_listed_as_passed()
    {
        $unverified = FeatureRequest::factory()->generated()->create();
        Verification::factory()->create(['feature_request_id' => $unverified->id, 'status' => VerificationStatus::Unverified]);
        $passed = FeatureRequest::factory()->generated()->create();
        Verification::factory()->create(['feature_request_id' => $passed->id, 'status' => VerificationStatus::Passed]);

        $this->actingAs($this->operator)
            ->get(route('operations.changes.index', ['verification' => 'passed']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.total', 1)
                ->where('changes.data.0.id', $passed->id)
                ->where('changes.data.0.verification', 'passed'));

        $history = app(DescribeChangeHistory::class)->handle($unverified);
        $this->assertSame('unverified', $history['milestones']['verification']);
    }

    public function test_the_list_filters_by_project_date_outcome_driver_model_provider_and_reason()
    {
        $project = Project::factory()->create();
        $match = FeatureRequest::factory()->generated()->create(['project_id' => $project->id, 'created_at' => now()->subDays(2), 'decision_model_calls' => [
            ['provider' => 'typesafe', 'model' => 'decider', 'input_tokens' => 10, 'output_tokens' => 0, 'cost_usd' => 0.25, 'cost_source' => 'estimated', 'at' => now()->toIso8601String()],
        ]]);
        $run = Run::factory()->create(['feature_request_id' => $match->id, 'driver' => 'agent', 'status' => RunStatus::Completed]);
        $run->recordEvent('status', ['from' => 'implementing', 'to' => 'needs_user_decision', 'reason' => 'budget_exhausted']);
        $run->recordEvent('model_call', ['provider' => 'anthropic', 'model' => 'coder-1', 'cost_usd' => 0.5, 'cost_source' => 'reported']);
        $run->recordEvent('model_call', ['provider' => 'anthropic', 'model' => 'coder-1', 'cost_usd' => null]);

        $other = FeatureRequest::factory()->generated()->create(['created_at' => now()->subDays(10)]);
        Run::factory()->create(['feature_request_id' => $other->id, 'status' => RunStatus::Failed, 'stop_reason' => 'construction_failed'])
            ->recordEvent('model_call', ['provider' => 'openai', 'model' => 'coder-2', 'cost_usd' => 0.1]);

        $filters = [
            ['project' => $project->id],
            ['from' => now()->subDays(3)->toDateString(), 'to' => now()->toDateString()],
            ['outcome' => 'built'],
            ['driver' => 'agent'],
            ['model' => 'coder-1'],
            ['provider' => 'anthropic'],
            ['reason' => 'budget_exhausted'],
        ];

        foreach ($filters as $filter) {
            $this->actingAs($this->operator)
                ->get(route('operations.changes.index', $filter))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('changes.total', 1)
                    ->where('changes.data.0.id', $match->id));
        }

        $this->actingAs($this->operator)
            ->get(route('operations.changes.index', ['reason' => 'construction_failed']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.total', 1)
                ->where('changes.data.0.id', $other->id)
                ->where('changes.data.0.outcome', 'failed'));

        $this->actingAs($this->operator)
            ->get(route('operations.changes.index', ['project' => $project->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('changes.data.0.calls', 3)
                ->where('changes.data.0.unpriced_calls', 1)
                ->where('changes.data.0.cost_usd', 0.75)
                ->where('changes.data.0.models', ['coder-1', 'decider'])
                ->missing('changes.data.0.prompt')
                ->missing('changes.data.0.patch'));
    }

    public function test_the_list_never_carries_what_the_owner_asked_for()
    {
        FeatureRequest::factory()->generated()->create(['prompt' => 'Secret launch plan for Acme']);

        $response = $this->actingAs($this->operator)->get(route('operations.changes.index'));

        $response->assertOk();
        $this->assertStringNotContainsString('Secret launch plan', (string) $response->getContent());
    }

    public function test_it_splits_a_changes_time_into_queue_machine_and_owner_time()
    {
        $start = now()->startOfMinute()->subHour();
        $change = FeatureRequest::factory()->generated()->create(['created_at' => $start]);
        $run = Run::factory()->create(['feature_request_id' => $change->id, 'status' => RunStatus::Completed, 'created_at' => $start, 'finished_at' => $start->addSeconds(700)]);

        // queued 30s, implementing 100s, owner 300s, implementing 50s,
        // verifying 200s of which checks ran 150s, reviewing 20s.
        $steps = [[30, 'queued', 'implementing'], [130, 'implementing', 'needs_user_decision'], [430, 'needs_user_decision', 'implementing'], [480, 'implementing', 'verifying'], [680, 'verifying', 'reviewing'], [700, 'reviewing', 'completed']];

        foreach ($steps as [$at, $from, $to]) {
            $this->travelTo($start->addSeconds($at));
            $run->recordEvent('status', ['from' => $from, 'to' => $to]);
        }

        Verification::factory()->create(['feature_request_id' => $change->id, 'run_id' => $run->id, 'status' => VerificationStatus::Passed, 'started_at' => $start->addSeconds(500), 'finished_at' => $start->addSeconds(650)]);
        $change->update(['commit_sha' => str_repeat('c', 40), 'accepted_at' => $start->addSeconds(760)]);

        $history = app(DescribeChangeHistory::class)->handle($change->fresh() ?? $change);

        $this->assertSame(30 + 50, $history['time']['queue_seconds']);
        $this->assertSame(100 + 50 + 150 + 20, $history['time']['machine_seconds']);
        $this->assertSame(300, $history['time']['owner_seconds']);
        $this->assertSame(700, $history['time']['total_seconds']);
        $this->assertSame(60, $history['time']['until_kept_seconds']);
        $this->assertSame(['queue' => 50, 'machine' => 150], $history['runs'][0]['segments'][4]['split']);
        $this->assertSame('kept', $history['change']['outcome']);
    }

    public function test_the_history_page_links_retries_and_hides_the_full_request()
    {
        $change = FeatureRequest::factory()->generated()->create(['prompt' => str_repeat('word ', 200)]);
        $retry = FeatureRequest::factory()->generated()->create(['project_id' => $change->project_id, 'retry_of_id' => $change->id, 'parent_id' => $change->id]);

        $this->actingAs($this->operator)
            ->get(route('operations.changes.show', $change))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('operations/Change')
                ->where('history.related.retries', [$retry->id])
                ->where('history.related.follow_ups', [])
                ->where('history.change.request', fn (string $request) => mb_strlen($request) <= 503)
                ->where('history.milestones.healthy', null)
                ->where('history.change.outcome_label', 'Built, not kept'));
    }
}
