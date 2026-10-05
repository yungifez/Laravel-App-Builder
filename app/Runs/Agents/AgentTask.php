<?php

namespace App\Runs\Agents;

use App\Models\User;

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
        // Our working rules. The gateway adds them to each model call on
        // our side, so the box holds only the task (architecture §16).
        public ?string $instructions = null,
        // The account whose monthly AI use the agent spends. The gateway
        // holds each of its calls to what is left.
        public ?User $owner = null,
    ) {}
}
