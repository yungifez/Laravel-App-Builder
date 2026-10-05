<?php

namespace App\Http\Controllers;

use App\Runs\GatewayInstructions;
use App\Runs\ModelGateway;
use App\Runs\ModelUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ModelGatewayController extends Controller
{
    /**
     * The request headers the provider needs, passed on as sent. The key
     * the agent sent is the gateway token, so it is replaced, never passed.
     */
    protected const PASSED_HEADERS = ['accept', 'content-type', 'anthropic-version', 'anthropic-beta', 'openai-beta', 'user-agent'];

    /**
     * Send an agent's model call on with the real key and our working
     * rules, and stream the answer back as it comes, counting what the
     * model read and wrote.
     */
    public function __invoke(Request $request, ModelGateway $gateway, GatewayInstructions $gatewayInstructions, string $provider, string $path = ''): Response
    {
        $token = (string) $request->attributes->get('gateway_token');
        $instructions = $gateway->instructions($token);
        $call = $instructions === null ? $request->getContent() : $gatewayInstructions->add($provider, $path, $request->getContent(), $instructions);
        $headers = array_filter(
            array_map(fn (array $values) => $values[0] ?? null, $request->headers->all()),
            fn (?string $value, string $name) => $value !== null && in_array($name, self::PASSED_HEADERS, true),
            ARRAY_FILTER_USE_BOTH,
        );
        $headers += $provider === 'anthropic'
            ? ['x-api-key' => $gateway->credential($provider)]
            : ['Authorization' => 'Bearer '.$gateway->credential($provider)];

        $query = $request->getQueryString();
        $response = Http::withHeaders($headers)
            ->withOptions(['stream' => true, 'http_errors' => false])
            ->timeout(600)
            ->send($request->method(), $gateway->upstream($provider).'/'.ltrim($path, '/').($query ? "?{$query}" : ''), [
                'body' => $call,
            ]);

        // An error is short and never streamed. It may quote the call, so
        // our rules are taken out before the box sees it.
        if ($instructions !== null && $response->failed()) {
            $gateway->count($token, 0, 0);

            return response($gatewayInstructions->scrub($response->body(), $instructions), $response->status(), array_filter([
                'Content-Type' => $response->header('Content-Type') ?: null,
                'request-id' => $response->header('request-id') ?: null,
            ]));
        }

        $body = $response->toPsrResponse()->getBody();
        $usage = new ModelUsage;

        return new StreamedResponse(function () use ($body, $usage, $gateway, $token) {
            try {
                while (! $body->eof()) {
                    $chunk = $body->read(8192);

                    if ($chunk === '') {
                        continue;
                    }

                    $usage->read($chunk);
                    echo $chunk;

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } finally {
                $gateway->count($token, $usage->inputTokens(), $usage->outputTokens());
            }
        }, $response->status(), array_filter([
            'Content-Type' => $response->header('Content-Type') ?: null,
            'request-id' => $response->header('request-id') ?: null,
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-cache',
        ]));
    }
}
