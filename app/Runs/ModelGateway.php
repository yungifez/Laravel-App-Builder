<?php

namespace App\Runs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * The one way our coding agents reach a model. A run gets a token that opens
 * the gateway for its provider only, for as long as the run may take; the
 * gateway sends each call on with the real key. The key stays in the
 * control plane, out of the workspace, where the agent has a shell.
 */
class ModelGateway
{
    /**
     * The environment variables each provider's SDK reads: the key, and
     * where to send calls. Codex expects its address to end in /v1.
     *
     * @var array<string, array{key: string, url: string, suffix: string}>
     */
    protected const CLIENTS = [
        'anthropic' => ['key' => 'ANTHROPIC_API_KEY', 'url' => 'ANTHROPIC_BASE_URL', 'suffix' => ''],
        'openai' => ['key' => 'OPENAI_API_KEY', 'url' => 'OPENAI_BASE_URL', 'suffix' => '/v1'],
    ];

    public function enabled(): bool
    {
        return Config::boolean('builder.agents.gateway.enabled');
    }

    /**
     * Whether the gateway can serve this provider.
     */
    public function serves(string $provider): bool
    {
        return isset(self::CLIENTS[$provider]);
    }

    /**
     * Get the variable that holds the provider's key for its SDK.
     */
    public function keyVariable(string $provider): ?string
    {
        return self::CLIENTS[$provider]['key'] ?? null;
    }

    /**
     * Open the gateway for one run and get the environment its agent needs
     * in place of the key. The token lasts as long as the run may take.
     *
     * @return array{token: string, environment: array<string, string>}
     */
    public function open(string $provider, int $seconds): array
    {
        $client = self::CLIENTS[$provider];
        $token = 'gw_'.Str::random(48);

        $until = now()->addSeconds($seconds + 60);

        Cache::put($this->key($token), ['provider' => $provider, 'requests' => 0, 'output_tokens' => 0, 'until' => $until->getTimestamp()], $until);

        return [
            'token' => $token,
            'environment' => [
                $client['key'] => $token,
                $client['url'] => rtrim(Config::string('builder.agents.gateway.url'), '/')."/api/gateway/{$provider}".$client['suffix'],
            ],
        ];
    }

    /**
     * Close the gateway for a run that ended.
     */
    public function close(string $token): void
    {
        Cache::forget($this->key($token));
    }

    /**
     * Get what a token opens, or null when it is unknown, closed or used up.
     *
     * @return array{provider: string, requests: int, output_tokens: int, until: int}|null
     */
    public function grant(string $token): ?array
    {
        if (! str_starts_with($token, 'gw_')) {
            return null;
        }

        /** @var array{provider: string, requests: int, output_tokens: int, until: int}|null $grant */
        $grant = Cache::get($this->key($token));

        if ($grant === null
            || $grant['requests'] >= Config::integer('builder.agents.gateway.max_requests')
            || $grant['output_tokens'] >= Config::integer('builder.agents.gateway.max_output_tokens')) {
            return null;
        }

        return $grant;
    }

    /**
     * Count one call and what the model wrote, against the run's limits.
     */
    public function count(string $token, int $outputTokens): void
    {
        $key = $this->key($token);

        Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $outputTokens) {
            /** @var array{provider: string, requests: int, output_tokens: int, until: int}|null $grant */
            $grant = Cache::get($key);

            if ($grant === null) {
                return;
            }

            $grant['requests']++;
            $grant['output_tokens'] += max(0, $outputTokens);

            // The token keeps its own end: put() without one would make it
            // last forever.
            Cache::put($key, $grant, Date::createFromTimestamp($grant['until']));
        });
    }

    /**
     * Get the address calls to the provider go to, without its version.
     */
    public function upstream(string $provider): string
    {
        return (string) preg_replace('#/v1/?$#', '', rtrim(Config::string("ai.providers.{$provider}.url"), '/'));
    }

    /**
     * Get the real key for the provider.
     */
    public function credential(string $provider): string
    {
        return (string) Config::get("ai.providers.{$provider}.key");
    }

    protected function key(string $token): string
    {
        return 'gateway:grant:'.hash('sha256', $token);
    }
}
