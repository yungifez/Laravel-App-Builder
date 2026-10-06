<?php

namespace Tests\Feature\Runs;

use App\Actions\Runs\StartRun;
use App\Ai\Agents\FeaturePlanner;
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
use Tests\Concerns\PreparesRuns;
use Tests\TestCase;

/**
 * Operators read what the AI service said when it refused a request, so
 * two refusals with the same status can be told apart. The owner never
 * reads it.
 */
class ServiceErrorMessageTest extends TestCase
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
            'ai.providers.anthropic.key' => 'anthropic-test-key',
        ]);
        $this->owner = User::factory()->create();
    }

    public function test_an_empty_account_keeps_what_the_service_said_for_operators(): void
    {
        $run = $this->runRefusedWith('Your credit balance is too low to access the Anthropic API.');

        $this->assertSame(StopReason::OutOfCredit, $run->stop_reason);
        $this->assertSame('Your credit balance is too low to access the Anthropic API.', $this->serviceError($run)['message']);
        $this->assertStringNotContainsString('credit balance', (string) $run->error);
    }

    public function test_a_refusal_with_the_same_status_is_told_apart_by_its_message(): void
    {
        $run = $this->runRefusedWith('The compiled grammar is too large.');

        $this->assertSame(StopReason::RequestRefused, $run->stop_reason);
        $this->assertSame(['reason' => 'request_refused', 'status' => 400, 'type' => 'invalid_request_error', 'message' => 'The compiled grammar is too large.'], $this->serviceError($run));
        $this->assertStringNotContainsString('grammar', (string) $run->error);
    }

    public function test_a_message_that_echoes_a_key_or_runs_long_is_scrubbed_and_cut_short(): void
    {
        $key = 'sk-ant-'.str_repeat('a', 40);
        $run = $this->runRefusedWith("Bad value near {$key} ".str_repeat('and more ', 100));
        $message = (string) $this->serviceError($run)['message'];

        $this->assertStringNotContainsString($key, $message);
        $this->assertStringContainsString('[secret removed]', $message);
        $this->assertLessThanOrEqual(303, mb_strlen($message));

        // A refusal with no JSON body keeps no message.
        $this->refuseWith('<html>Bad Request</html>', 'text/html');
        $this->assertNull($this->serviceError(app(StartRun::class)->handle($this->change())->refresh())['message']);
    }

    protected function runRefusedWith(string $message): Run
    {
        $this->refuseWith((string) json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => $message]]));

        return app(StartRun::class)->handle($this->change())->refresh();
    }

    protected function refuseWith(string $body, string $contentType = 'application/json'): void
    {
        FeaturePlanner::fake(fn () => throw new RequestException(new Response(new Psr7Response(400, ['Content-Type' => $contentType], $body))));
    }

    /**
     * @return array<string, mixed>
     */
    protected function serviceError(Run $run): array
    {
        return $run->events()->where('type', 'ai_service_error')->sole()->data;
    }

    protected function change(): FeatureRequest
    {
        $project = Project::factory()->for($this->owner, 'owner')->create(['source_path' => $this->makeProjectSource()]);

        return FeatureRequest::factory()->for($project)->for($this->owner, 'user')->create();
    }
}
