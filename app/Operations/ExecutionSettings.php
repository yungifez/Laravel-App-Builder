<?php

namespace App\Operations;

use App\Models\ExecutionConfig;

/**
 * The settings that shape how a change is built: drivers, models, budgets
 * and checks. Each run records their version, so results can be compared
 * across configuration changes.
 *
 * The settings are named one by one below, never copied wholesale, so a
 * key, token or other credential added to the config can never land here.
 */
class ExecutionSettings
{
    /**
     * The config keys that make up the settings.
     *
     * @var list<string>
     */
    public const KEYS = [
        'builder.generator',
        'builder.construction.driver',
        'builder.construction.workspace_driver',
        'builder.construction.budgets',
        'builder.construction.questions',
        'builder.models.planner.provider',
        'builder.models.planner.model',
        'builder.models.coder.provider',
        'builder.models.coder.model',
        'builder.models.reviewer.provider',
        'builder.models.reviewer.model',
        'builder.models.failover',
        'builder.agents.order',
        'builder.agents.adapters.claude.model',
        'builder.agents.adapters.codex.model',
        'builder.agents.max_turns',
        'builder.agents.max_budget_usd',
        'builder.verification.workspace_driver',
        'builder.verification.require_verify_tests',
        'builder.verification.suite_paths',
        'builder.verification.suite_suffixes',
        'builder.preview.workspace_driver',
        'builder.decisions.providers',
        'workspaces.default',
        'workspaces.drivers.runner.provider',
        'workspaces.size',
    ];

    /**
     * Get the current settings.
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $settings = [];

        foreach (self::KEYS as $key) {
            $settings[$key] = config($key);
        }

        return $settings;
    }

    /**
     * Store the current settings once and get their version.
     */
    public static function record(): string
    {
        $settings = self::current();
        $version = hash('sha256', (string) json_encode($settings));

        ExecutionConfig::query()->firstOrCreate(['version' => $version], ['settings' => $settings]);

        return $version;
    }
}
