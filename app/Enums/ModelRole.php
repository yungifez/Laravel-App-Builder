<?php

namespace App\Enums;

/**
 * The AI model tiers used to build features. Each role has its own provider and
 * model in config/builder.php, so the tiers can be tuned or collapsed into a
 * single model without code changes.
 */
enum ModelRole: string
{
    /** Turns a request into a saved plan with acceptance criteria (frontier tier). */
    case Planner = 'planner';

    /** Carries out the plan through workspace tools (economical tier). */
    case Coder = 'coder';

    /** Judges the change from independently assembled evidence, never the coder's claims. */
    case Reviewer = 'reviewer';

    /**
     * Get the configured provider, falling back to the AI SDK's default provider.
     */
    public function provider(): string
    {
        $provider = config("builder.models.{$this->value}.provider");

        return is_string($provider) && $provider !== ''
            ? $provider
            : (string) config('ai.default');
    }

    /**
     * Get the configured model, or null to use the provider's default model.
     */
    public function model(): ?string
    {
        $model = config("builder.models.{$this->value}.model");

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Get the providers to try in order, with their models: the role's own
     * first, then each failover provider that has a key, on its default
     * model. The AI SDK moves to the next one on provider trouble.
     *
     * @return array<string, string|null>
     */
    public function providers(): array
    {
        $providers = [$this->provider() => $this->model()];

        foreach ((array) config('builder.models.failover') as $provider) {
            if (is_string($provider) && filled(config("ai.providers.{$provider}.key"))) {
                $providers += [$provider => null];
            }
        }

        return $providers;
    }
}
