<?php

namespace Tests\Feature\Runs;

use App\Runs\ModelGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A coding agent reaches its model through the control plane with its run's
 * token; the call goes on with the real key, which never enters a workspace.
 */
class ModelGatewayTest extends TestCase
{
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

        $this->assertSame(1, app(ModelGateway::class)->grant($opened['token'])['requests']);
        $this->assertSame(250, app(ModelGateway::class)->grant($opened['token'])['output_tokens']);
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
