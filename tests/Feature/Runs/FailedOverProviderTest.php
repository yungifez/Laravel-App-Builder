<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Enums\StopReason;
use App\Jobs\VerifyFeatureRequest;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * When one AI service turns a request away and the next one does too, the
 * reason of each is kept, and an empty account wins over a busy service:
 * it is the one we must put right.
 */
class FailedOverProviderTest extends TestCase
{
    use PreparesRuns, RefreshDatabase;

    protected User $owner;

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
            'builder.models.reviewer' => ['provider' => 'anthropic', 'model' => 'reviewer-model'],
            'builder.models.failover' => ['openai'],
            'ai.providers.anthropic.key' => 'anthropic-test-key',
            'ai.providers.openai.key' => 'openai-test-key',
        ]);
        $this->owner = User::factory()->create();
    }

    public function test_an_empty_account_before_a_busy_service_stops_for_credit_and_keeps_both_reasons(): void
    {
        $this->answer(anthropic: $this->outOfCredit(), openai: $this->tooMany());

        $run = app(StartRun::class)->handle($this->change())->refresh();

        $this->assertSame(StopReason::OutOfCredit, $run->stop_reason);
        $this->assertSame(StopReason::OutOfCredit->said(), $run->error);
        $this->assertSame([
            ['reason' => 'out_of_credit', 'provider' => 'anthropic', 'status' => 400, 'type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.'],
            ['reason' => 'providers_unavailable', 'provider' => 'openai', 'status' => 429, 'type' => 'requests', 'message' => 'Rate limit reached for requests.'],
        ], $this->serviceErrors($run));
    }

    public function test_two_busy_services_stay_a_wait(): void
    {
        $this->answer(anthropic: $this->tooMany(), openai: $this->tooMany());

        $run = app(StartRun::class)->handle($this->change())->refresh();

        $this->assertSame(StopReason::ProvidersUnavailable, $run->stop_reason);
        $this->assertStringEndsWith('Try again in a few minutes.', (string) $run->error);
        $this->assertSame(['anthropic', 'openai'], array_column($this->serviceErrors($run), 'provider'));
        $this->assertSame(['providers_unavailable', 'providers_unavailable'], array_column($this->serviceErrors($run), 'reason'));

        // An empty account in an earlier change does not colour this one.
        $this->answer(anthropic: $this->outOfCredit(), openai: $this->tooMany());
        app(StartRun::class)->handle($this->change());
        $this->answer(anthropic: $this->tooMany(), openai: $this->tooMany());

        $this->assertSame(StopReason::ProvidersUnavailable, app(StartRun::class)->handle($this->change())->refresh()->stop_reason);
    }

    public function test_a_failover_for_no_run_records_nothing_and_does_not_throw(): void
    {
        $this->answer(anthropic: $this->outOfCredit(), openai: $this->tooMany());

        $this->artisan('ai:check-formats')->assertFailed();

        $this->assertSame(0, RunEvent::query()->where('type', 'ai_service_error')->count());
    }

    /**
     * Answer every call to each AI service with the given error.
     *
     * @param  array{int, array<string, mixed>}  $anthropic
     * @param  array{int, array<string, mixed>}  $openai
     */
    protected function answer(array $anthropic, array $openai): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($anthropic[1], $anthropic[0]),
            'api.openai.com/*' => Http::response($openai[1], $openai[0]),
        ]);
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    protected function outOfCredit(): array
    {
        return [400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.']]];
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    protected function tooMany(): array
    {
        return [429, ['error' => ['type' => 'requests', 'code' => 'rate_limit_exceeded', 'message' => 'Rate limit reached for requests.']]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function serviceErrors(Run $run): array
    {
        return $run->events()->where('type', 'ai_service_error')->orderBy('sequence')->pluck('data')->all();
    }

    protected function change(): FeatureRequest
    {
        $project = Project::factory()->for($this->owner, 'owner')->create(['source_path' => $this->makeProjectSource()]);

        return FeatureRequest::factory()->for($project)->for($this->owner, 'user')->create();
    }
}
