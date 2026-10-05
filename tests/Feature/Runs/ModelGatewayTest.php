<?php

namespace Tests\Feature\Runs;

use App\Models\ModelGatewayGrant;
use App\Runs\ModelGateway;
use App\Runs\ModelUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A coding agent reaches its model through the control plane with its run's
 * token; the call goes on with the real key, which never enters a workspace.
 */
class ModelGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected const STREAM = "event: message_start\ndata: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":12,\"output_tokens\":1}}}\n\n"
        ."event: message_delta\ndata: {\"type\":\"message_delta\",\"usage\":{\"output_tokens\":250}}\n\n";

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.anthropic.key' => 'real-anthropic-key',
            'ai.providers.anthropic.url' => 'https://api.anthropic.com/v1',
            'ai.providers.openai.key' => 'real-openai-key',
            'ai.providers.openai.url' => 'https://api.openai.com/v1',
            'builder.agents.gateway.url' => 'http://control-plane.test',
        ]);
    }

    public function test_a_call_with_the_run_token_goes_on_with_the_real_key_and_streams_back()
    {
        Http::fake(['api.anthropic.com/*' => Http::response(self::STREAM, 200, ['Content-Type' => 'text/event-stream'])]);
        $opened = app(ModelGateway::class)->open('anthropic', 600);

        $this->assertSame([
            'ANTHROPIC_API_KEY' => $opened['token'],
            'ANTHROPIC_BASE_URL' => 'http://control-plane.test/api/gateway/anthropic',
        ], $opened['environment']);

        $response = $this->call('POST', '/api/gateway/anthropic/v1/messages?beta=true', [], [], [], [
            'HTTP_X_API_KEY' => $opened['token'],
            'HTTP_ANTHROPIC_VERSION' => '2023-06-01',
            'CONTENT_TYPE' => 'application/json',
        ], '{"model":"claude","messages":[]}');

        $response->assertOk();
        $this->assertSame(self::STREAM, $response->streamedContent());
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages?beta=true'
            && $request->header('x-api-key') === ['real-anthropic-key']
            && $request->header('anthropic-version') === ['2023-06-01']
            && $request->body() === '{"model":"claude","messages":[]}'
            && ! str_contains((string) json_encode($request->headers()), $opened['token']));

        $grant = app(ModelGateway::class)->grant($opened['token']);
        $this->assertSame([1, 12, 250], [$grant['requests'] ?? null, $grant['input_tokens'] ?? null, $grant['output_tokens'] ?? null]);
    }

    public function test_a_run_keeps_its_grant_when_the_cache_is_emptied_and_what_it_spent_after_it_ends()
    {
        Http::fake(['api.anthropic.com/*' => Http::response(self::STREAM)]);
        $gateway = app(ModelGateway::class);
        $opened = $gateway->open('anthropic', 600);

        Cache::flush();

        $this->postJson('/api/gateway/anthropic/v1/messages', [], ['x-api-key' => $opened['token']])->assertOk()->streamedContent();
        $gateway->close($opened['token']);

        // Only the token's hash is kept.
        $grant = ModelGatewayGrant::query()->sole();
        $this->assertSame(hash('sha256', $opened['token']), $grant->token_hash);
        $this->assertSame(['anthropic', 1, 12, 250], [$grant->provider, $grant->requests, $grant->input_tokens, $grant->output_tokens]);
        $this->assertNotNull($grant->closed_at);
        $this->assertNull($gateway->grant($opened['token']));
    }

    public function test_input_is_read_from_either_providers_answer_with_what_came_from_the_prompt_cache()
    {
        $anthropic = new ModelUsage;
        $anthropic->read('{"usage":{"input_tokens":12,"cache_creation_input_tokens":300,"cache_read_input_t');
        $anthropic->read('okens":4000,"output_tokens":1}}');
        $anthropic->read('{"usage":{"output_tokens":250}}');

        $chat = new ModelUsage;
        $chat->read('{"usage":{"prompt_tokens":90,"completion_tokens":30}}');

        $nothing = new ModelUsage;
        $nothing->read('{"error":{"message":"overloaded"}}');

        $this->assertSame([4312, 250], [$anthropic->inputTokens(), $anthropic->outputTokens()]);
        $this->assertSame([90, 30], [$chat->inputTokens(), $chat->outputTokens()]);
        $this->assertSame([0, 0], [$nothing->inputTokens(), $nothing->outputTokens()]);
    }

    public function test_grants_are_pruned_a_month_after_they_end()
    {
        $gateway = app(ModelGateway::class);
        $gateway->open('anthropic', 600);
        $this->travel(32)->days();
        $recent = $gateway->open('openai', 600);

        Artisan::call('model:prune', ['--model' => [ModelGatewayGrant::class]]);

        $this->assertSame(['openai'], ModelGatewayGrant::query()->pluck('provider')->all());
        $this->assertNotNull($gateway->grant($recent['token']));
    }

    public function test_the_gateway_adds_our_rules_to_each_call_so_the_box_never_holds_them()
    {
        Http::fake(['api.anthropic.com/*' => Http::response(self::STREAM), 'api.openai.com/*' => Http::response('{"usage":{"output_tokens":4}}')]);
        $gateway = app(ModelGateway::class);
        $claude = $gateway->open('anthropic', 600, 'Explain each step in plain words.');
        $codex = $gateway->open('openai', 600, 'Explain each step in plain words.');

        $this->call('POST', '/api/gateway/anthropic/v1/messages', [], [], [], ['HTTP_X_API_KEY' => $claude['token'], 'CONTENT_TYPE' => 'application/json'],
            '{"model":"claude","system":"You are a coding agent.","tools":[{"name":"t","input_schema":{"type":"object","properties":{}}}],"messages":[]}')->streamedContent();
        $this->call('POST', '/api/gateway/anthropic/v1/messages', [], [], [], ['HTTP_X_API_KEY' => $claude['token'], 'CONTENT_TYPE' => 'application/json'],
            '{"model":"claude","system":[{"type":"text","text":"You are a coding agent.","cache_control":{"type":"ephemeral"}}],"messages":[]}')->streamedContent();
        $this->withToken($codex['token'])->postJson('/api/gateway/openai/v1/responses', ['model' => 'gpt', 'instructions' => 'You are Codex.', 'input' => []])->assertOk();
        $this->withToken($codex['token'])->postJson('/api/gateway/openai/v1/chat/completions', ['model' => 'gpt', 'messages' => [['role' => 'user', 'content' => 'Hi']]])->assertOk();

        $sent = Http::recorded()->map(fn (array $pair) => json_decode($pair[0]->body(), true))->all();
        $ours = ['type' => 'text', 'text' => 'Explain each step in plain words.'];
        $this->assertSame([['type' => 'text', 'text' => 'You are a coding agent.'], $ours], $sent[0]['system']);
        // An empty schema stays an object, as the provider needs it.
        $this->assertStringContainsString('"properties":{}', Http::recorded()->first()[0]->body());
        $this->assertSame([['type' => 'text', 'text' => 'You are a coding agent.', 'cache_control' => ['type' => 'ephemeral']], $ours], $sent[1]['system']);
        $this->assertSame("You are Codex.\n\nExplain each step in plain words.", $sent[2]['instructions']);
        $this->assertSame([['role' => 'system', 'content' => 'Explain each step in plain words.'], ['role' => 'user', 'content' => 'Hi']], $sent[3]['messages']);
    }

    public function test_an_error_that_quotes_the_call_never_shows_our_rules_to_the_box()
    {
        $rules = "## How to work\n\nBefore each group of steps, write one or two plain sentences on what you are about to do.";
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'system.1.text: "'.$rules.'" is too long']], 400)]);
        $opened = app(ModelGateway::class)->open('anthropic', 600, $rules);

        $response = $this->postJson('/api/gateway/anthropic/v1/messages', ['model' => 'claude', 'messages' => []], ['x-api-key' => $opened['token']]);

        $response->assertStatus(400);
        $this->assertStringNotContainsString('Before each group of steps', $response->getContent());
        $this->assertStringContainsString('is too long', $response->getContent());
        $this->assertSame(1, app(ModelGateway::class)->grant($opened['token'])['requests'] ?? null);
    }

    public function test_an_openai_agent_sends_its_token_as_a_bearer_token()
    {
        Http::fake(['api.openai.com/*' => Http::response('{"usage":{"output_tokens":40}}')]);
        $opened = app(ModelGateway::class)->open('openai', 600);

        $this->assertSame('http://control-plane.test/api/gateway/openai/v1', $opened['environment']['OPENAI_BASE_URL']);

        $this->withToken($opened['token'])
            ->postJson('/api/gateway/openai/v1/responses', ['model' => 'gpt'])
            ->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses'
            && $request->header('Authorization') === ['Bearer real-openai-key']);
    }

    public function test_only_an_open_token_for_the_same_provider_gets_through()
    {
        Http::fake();
        $gateway = app(ModelGateway::class);
        $opened = $gateway->open('anthropic', 600);

        $this->postJson('/api/gateway/anthropic/v1/messages')->assertUnauthorized();
        $this->postJson('/api/gateway/anthropic/v1/messages', [], ['x-api-key' => 'real-anthropic-key'])->assertUnauthorized();
        $this->postJson('/api/gateway/anthropic/v1/messages', [], ['x-api-key' => 'gw_made_up'])->assertUnauthorized();
        $this->withToken($opened['token'])->postJson('/api/gateway/openai/v1/responses')->assertUnauthorized();

        $gateway->close($opened['token']);
        $this->postJson('/api/gateway/anthropic/v1/messages', [], ['x-api-key' => $opened['token']])->assertUnauthorized();

        $other = $gateway->open('anthropic', 60);
        $this->travel(5)->minutes();
        $this->postJson('/api/gateway/anthropic/v1/messages', [], ['x-api-key' => $other['token']])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_run_that_used_up_its_limit_is_refused_whatever_the_agent_was_told()
    {
        config(['builder.agents.gateway.max_output_tokens' => 400]);
        Http::fake(['api.anthropic.com/*' => fn () => Http::response(self::STREAM)]);
        $opened = app(ModelGateway::class)->open('anthropic', 600);
        $call = fn () => $this->postJson('/api/gateway/anthropic/v1/messages', [], ['x-api-key' => $opened['token']]);

        $call()->assertOk()->streamedContent();
        $call()->assertOk()->streamedContent();
        $call()->assertUnauthorized();

        Http::assertSentCount(2);
    }
}
