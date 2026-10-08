<?php

namespace App\Evaluation;

use App\Enums\AgentOutcomeStatus;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Contracts\CodingAgent;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A coding agent whose work is done by an outside responder in the run's
 * local workspace directory, through the hand-off.
 */
class HandoffCodingAgent implements CodingAgent
{
    public function __construct(
        protected Handoff $handoff,
        protected string $adapter = 'claude',
    ) {}

    public function provider(): string
    {
        return 'anthropic';
    }

    public function run(Workspace $workspace, AgentTask $task): AgentOutcome
    {
        if ($workspace->driver !== 'local' || $workspace->driver_id === null) {
            throw new RuntimeException('The hand-off coding agent works only in local workspaces.');
        }

        $response = $this->handoff->ask('coder', [
            'workspace' => rtrim((string) config('workspaces.drivers.local.root'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$workspace->driver_id,
            'prompt' => $task->prompt,
            'max_turns' => $task->maxTurns,
            'timeout_seconds' => $task->timeoutSeconds,
        ]);

        return new AgentOutcome(
            adapter: $this->adapter,
            provider: $this->provider(),
            model: 'handoff',
            status: AgentOutcomeStatus::tryFrom((string) ($response['status'] ?? '')) ?? AgentOutcomeStatus::Failed,
            summary: isset($response['summary']) ? Str::limit((string) $response['summary'], 4000) : null,
            errorKind: isset($response['error']) ? 'handoff' : null,
            error: isset($response['error']) ? (string) $response['error'] : null,
        );
    }
}
