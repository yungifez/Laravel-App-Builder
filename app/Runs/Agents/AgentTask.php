<?php

namespace App\Runs\Agents;

/**
 * One coding task for an agent working in the run's workspace.
 */
final readonly class AgentTask
{
    /**
     * @param  array{adapter: string, session: string, prompt: string}|null  $resume  For a repair pass: the session an agent built the change in, to continue with only this prompt
     */
    public function __construct(
        public string $prompt,
        public ?int $maxTurns = null,
        public ?float $maxBudgetUsd = null,
        public int $timeoutSeconds = 1200,
        // A small, well-defined task: the agent's light model may take it.
        public bool $light = false,
        public ?array $resume = null,
        // The agent to try first, ahead of the configured order.
        public ?string $prefer = null,
    ) {}
}
