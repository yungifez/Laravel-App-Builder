<?php

namespace App\Runs\Agents;

use App\Enums\AgentTier;
use App\Models\User;

/**
 * One coding task for an agent working in the run's workspace.
 */
final readonly class AgentTask
{
    /**
     * @param  array{adapter: string, session: string, prompt: string, continue?: bool}|null  $resume  For a repair pass: the session an agent built the change in, to continue with only this prompt. With "continue", a session that is gone ends the task instead of starting fresh
     */
    public function __construct(
        public string $prompt,
        public ?int $maxTurns = null,
        public ?float $maxBudgetUsd = null,
        public int $timeoutSeconds = 1200,
        // Which of the agent's models takes it.
        public AgentTier $tier = AgentTier::Usual,
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

    /**
     * Get this task as the continuation of a session that was cut off part
     * way: the agent is only told to finish, and never starts fresh on the
     * session's half-done edits.
     */
    public function continuing(string $adapter, string $session): self
    {
        return new self(...[...get_object_vars($this), 'resume' => [
            'adapter' => $adapter,
            'session' => $session,
            'prompt' => __('You were stopped part way through this task. Your changes so far are in the files. Check where you got to, then finish the task as it was given.'),
            'continue' => true,
        ]]);
    }
}
