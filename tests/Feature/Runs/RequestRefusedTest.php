<?php

namespace Tests\Feature\Runs;

use App\Actions\Operations\FindAttentionItems;
use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
use App\Ai\Agents\TestWriter;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\User;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * An AI service that answers with an error stops the change at once, says
 * whose fault it is and what to do, and is never asked the same thing
 * again and again.
 */
class RequestRefusedTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected const REFUSED = 'This is our fault: the AI service we use could not accept how we asked it. We have been told. Nothing in your app changed. Try again later.';

    protected const UNREACHABLE = 'This is our fault: we could not reach the AI service we use. Nothing in your app changed. Try again in a few minutes.';

    protected User $owner;

    protected int $asked = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([VerifyFeatureRequest::class]);
        $this->buildInLocalWorkspaces();
        config([
            'operations.operators' => [],
            'builder.construction.driver' => 'sdk',
            'builder.generators.reference.path' => null,
            'builder.models.planner' => ['provider' => 'anthropic', 'model' => 'planner-model'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);
        $this->owner = User::factory()->create();
    }

    public function test_a_request_the_ai_service_refuses_stops_once_and_we_are_told(): void
    {
        $run = $this->runAnswered(400, 'invalid_request_error');

        $this->assertSame(1, $this->asked);
        $this->assertSame(RunStatus::NeedsUserDecision, $run->status);
        $this->assertSame(StopReason::RequestRefused, $run->stop_reason);
        $this->assertSame(self::REFUSED, $run->error);
        // Operators see what the service said, and never what we asked.
        $this->assertSame(['reason' => 'request_refused', 'status' => 400, 'type' => 'invalid_request_error'], $run->events()->where('type', 'ai_service_error')->sole()->data);
        $this->assertSame(["Run {$run->id}"], array_column($this->attention('ai_request_refused')['records'] ?? [], 'label'));
        $this->assertNextStep($run, self::REFUSED);
    }

    public function test_a_failing_ai_service_is_worth_another_try_and_is_not_on_the_list(): void
    {
        $run = $this->runAnswered(500, 'api_error');

        $this->assertSame(1, $this->asked);
        $this->assertSame(StopReason::ProvidersUnavailable, $run->stop_reason);
        $this->assertSame(self::UNREACHABLE, $run->error);
        $this->assertNull($this->attention('ai_request_refused'));
        $this->assertNextStep($run, self::UNREACHABLE);
    }

    public function test_a_bad_key_is_never_told_to_wait_a_few_minutes(): void
    {
        $run = $this->runAnswered(401, 'authentication_error');

        $this->assertSame(StopReason::RequestRefused, $run->stop_reason);
        $this->assertStringEndsNotWith('Try again in a few minutes.', (string) $run->error);
        $this->assertNotNull($this->attention('ai_request_refused'));
    }

    public function test_too_many_requests_stay_a_wait_and_a_repeated_refusal_keeps_its_own_advice(): void
    {
        $limited = $this->runAnswered(429, 'rate_limit_error');

        $this->assertSame(StopReason::ProvidersUnavailable, $limited->stop_reason);
        $this->assertSame(1, $this->asked);
        $this->assertStringEndsWith('Try again in a few minutes.', (string) $limited->error);

        // Tried again after the same refusal: still our fault, so the owner
        // is not sent to our developers as if the change were the problem.
        $first = $this->runAnswered(400, 'invalid_request_error');
        $again = FeatureRequest::factory()->for($first->featureRequest->project)->for($this->owner, 'user')->create(['retry_of_id' => $first->feature_request_id]);
        $this->refusePlans(400, 'invalid_request_error');
        $retry = app(StartRun::class)->handle($again)->refresh();

        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $retry->featureRequest))
            ->assertInertia(fn (Assert $page) => $page
                ->where('featureRequest.failed_same_way', false)
                ->where('run.error', self::REFUSED)
                ->etc());
    }

    public function test_the_model_writing_tests_first_is_refused_the_same_way(): void
    {
        config(['builder.verification.written_first.enabled' => true]);
        $plan = [
            'summary' => 'Teams get an optional description.',
            'acceptance_criteria' => ['Teams have a description.'],
            'cases' => [['base' => 'A team saved with a description keeps it.', 'alternate' => '', 'no_alternate' => 'A description is only set one way.', 'exception' => '', 'no_exception' => 'Nothing about a description is refused.']],
            'assumptions' => [],
            'tasks' => ['Add a description.'],
            'steps' => [['key' => 'description', 'kind' => 'data', 'label' => 'Team description', 'file' => 'app/Models/Team.php', 'symbol' => 'Team', 'detail' => 'Holds a description.']],
        ];
        FeaturePlanner::fake([$plan, $plan]);
        $answer = function (int $status, string $type) {
            $this->asked = 0;
            TestWriter::fake(function () use ($status, $type) {
                $this->asked++;

                throw new RequestException(new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], (string) json_encode(['error' => ['type' => $type]]))));
            });

            return app(StartRun::class)->handle($this->change())->refresh();
        };

        $refused = $answer(400, 'invalid_request_error');

        $this->assertSame(1, $this->asked);
        $this->assertSame(StopReason::RequestRefused, $refused->stop_reason);
        $this->assertSame(self::REFUSED, $refused->error);
        $this->assertSame(['reason' => 'request_refused', 'status' => 400, 'type' => 'invalid_request_error'], $refused->events()->where('type', 'ai_service_error')->sole()->data);

        $failing = $answer(503, 'overloaded_error');

        $this->assertSame(1, $this->asked);
        $this->assertSame(StopReason::ProvidersUnavailable, $failing->stop_reason);
        $this->assertSame(self::UNREACHABLE, $failing->error);
    }

    /**
     * Start a change whose planner call the AI service answers with an error.
     */
    protected function runAnswered(int $status, string $type): Run
    {
        $this->refusePlans($status, $type);

        return app(StartRun::class)->handle($this->change())->refresh();
    }

    protected function refusePlans(int $status, string $type): void
    {
        $this->asked = 0;
        $body = json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'The request echoed back.']]);

        FeaturePlanner::fake(function () use ($status, $body) {
            $this->asked++;

            throw new RequestException(new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], (string) $body)));
        });
    }

    protected function change(): FeatureRequest
    {
        $project = Project::factory()->for($this->owner, 'owner')->create(['source_path' => $this->makeProjectSource()]);

        return FeatureRequest::factory()->for($project)->for($this->owner, 'user')->create();
    }

    protected function assertNextStep(Run $run, string $error): void
    {
        $this->actingAs($this->owner)
            ->get(route('feature-requests.show', $run->featureRequest))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('run.error', $error)
                ->where('featureRequest.can_retry', true)
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
