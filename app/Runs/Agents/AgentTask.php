<?php

namespace App\Runs\Agents;

/**
 * One coding task for an agent working in the run's workspace.
 */
final readonly class AgentTask
{
    public function __construct(
        public string $prompt,
        public ?int $maxTurns = null,
        public ?float $maxBudgetUsd = null,
        public int $timeoutSeconds = 1200,
    ) {}
}
