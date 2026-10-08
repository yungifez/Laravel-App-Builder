<?php

namespace App\Runs;

use App\Actions\Billing\MeasureUsage;
use App\Models\ModelGatewayGrant;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * The one way our coding agents reach a model. A run gets a token that opens
 * the gateway for its provider only, for as long as the run may take; the
 * gateway sends each call on with the real key. The key stays in the
 * control plane, out of the workspace, where the agent has a shell. Grants
 * are kept in the database (ModelGatewayGrant), so what each run spent
 * stays readable after it ends.
 *
 * Every call passes here, so here is where an account's monthly AI use is
 * held to its plan while an agent works, not only before each step. A
 * grant takes what is left of it when it opens, and the account's runs
 * share that.
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
     * Run tokens open only the model calls the coding SDKs need.
     */
    public function allowsRequest(string $provider, string $method, string $path): bool
    {
        return $method === 'POST' && in_array($path, match ($provider) {
            'anthropic' => ['v1/messages', 'v1/messages/count_tokens'],
            'openai' => ['v1/responses', 'v1/responses/compact', 'v1/chat/completions'],
            default => [],
        }, true);
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
     * Our working rules, when given, are added to each of the run's calls
     * on our side (GatewayInstructions), so the box never holds them.
     * The owner's account, when given, holds the run to what is left of
     * its monthly AI use.
     *
     * @return array{token: string, environment: array<string, string>}
     */
    public function open(string $provider, int $seconds, ?string $instructions = null, ?User $owner = null): array
    {
        $client = self::CLIENTS[$provider];
        $token = 'gw_'.Str::random(48);
        $usage = $owner === null ? null : app(MeasureUsage::class)->handle($owner);

        ModelGatewayGrant::query()->create([
            'token_hash' => $this->hash($token),
            'provider' => $provider,
            'instructions' => $instructions,
            'user_id' => $owner?->id,
            'allowance_usd' => $usage === null || $usage['unlimited'] ? null : max(0.0, $usage['allowance_usd'] - $usage['used_usd']),
            'expires_at' => now()->addSeconds($seconds + 60),
        ]);

        return [
            'token' => $token,
            'environment' => [
                $client['key'] => $token,
                $client['url'] => rtrim(Config::string('builder.agents.gateway.url'), '/')."/api/gateway/{$provider}".$client['suffix'],
            ],
        ];
    }

    /**
     * Close the gateway for a run that ended. The grant stays, with what
     * the run spent.
     */
    public function close(string $token): void
    {
        ModelGatewayGrant::query()->where('token_hash', $this->hash($token))->whereNull('closed_at')->update(['closed_at' => now()]);
    }

    /**
     * Get what a token opens, or null when it is unknown, closed, expired or
     * used up.
     *
     * @return array{provider: string, requests: int, input_tokens: int, output_tokens: int, until: int}|null
     */
    public function grant(string $token): ?array
    {
        if (! str_starts_with($token, 'gw_')) {
            return null;
        }

        $grant = ModelGatewayGrant::query()
            ->where('token_hash', $this->hash($token))
            ->whereNull('closed_at')
            ->where('expires_at', '>', now())
            ->where('requests', '<', Config::integer('builder.agents.gateway.max_requests'))
            ->where('output_tokens', '<', Config::integer('builder.agents.gateway.max_output_tokens'))
            ->first();

        return $grant === null ? null : [
            'provider' => $grant->provider,
            'requests' => $grant->requests,
            'input_tokens' => $grant->input_tokens,
            'output_tokens' => $grant->output_tokens,
            'until' => $grant->expires_at->getTimestamp(),
        ];
    }

    /**
     * Get our working rules for the run a token was opened for, if any.
     */
    public function instructions(string $token): ?string
    {
        return ModelGatewayGrant::query()->where('token_hash', $this->hash($token))->first()?->instructions;
    }

    /**
     * Count one call and what the model read and wrote, against the run's
     * limits. A call that ends after its run closed still counts: it was
     * spent.
     */
    public function count(string $token, int $inputTokens, int $outputTokens, float $costUsd = 0.0): void
    {
        ModelGatewayGrant::query()->where('token_hash', $this->hash($token))->incrementEach([
            'requests' => 1,
            'input_tokens' => max(0, $inputTokens),
            'output_tokens' => max(0, $outputTokens),
            'cost_usd' => max(0.0, $costUsd),
        ]);
    }

    /**
     * Determine if the account may spend more through this grant. What was
     * left when it opened is shared with the account's other runs: each
     * run still open, or closed since, spent from it. A run closed before
     * was already counted in what was left. A call past it is refused, and
     * the grant remembers, so the run stops for the plan rather than
     * failing.
     */
    public function withinAllowance(string $token): bool
    {
        $grant = ModelGatewayGrant::query()->where('token_hash', $this->hash($token))->first();

        if ($grant === null || $grant->allowance_usd === null || $grant->user_id === null) {
            return true;
        }

        $spent = (float) ModelGatewayGrant::query()
            ->where('user_id', $grant->user_id)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>', $grant->created_at))
            ->sum('cost_usd');

        if ($spent < (float) $grant->allowance_usd) {
            return true;
        }

        $grant->forceFill(['refused_at' => now()])->save();

        return false;
    }

    /**
     * Determine if a call through the grant was refused because the
     * account used all its monthly AI use.
     */
    public function refused(string $token): bool
    {
        return ModelGatewayGrant::query()->where('token_hash', $this->hash($token))->whereNotNull('refused_at')->exists();
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

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
