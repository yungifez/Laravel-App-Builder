<?php

namespace App\Runs\Agents;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Workspace;
use App\Runs\Contracts\CodingAgent;
use App\Workspaces\WorkspaceManager;

/**
 * Runs an agent SDK through the Node runner (resources/agent-runner) inside
 * the workspace. The credentials reach only the runner's process, and are
 * never written to the workspace or the command log.
 */
class RunnerAgent implements CodingAgent
{
    /**
     * Where the task file goes while the agent runs. It is removed afterwards.
     */
    public const TASK_DIRECTORY = '.builder-run';

    /**
     * @param  array<string, string>  $credentials  Environment variables for the runner
     */
    public function __construct(
        protected string $adapter,
        protected string $provider,
        protected ?string $model,
        protected array $credentials,
        protected WorkspaceManager $workspaces,
        protected RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function run(Workspace $workspace, AgentTask $task): AgentOutcome
    {
        $taskFile = self::TASK_DIRECTORY.'/task.json';

        $this->workspaces->driver($workspace->driver)->writeFile((string) $workspace->driver_id, $taskFile, (string) json_encode([
            'adapter' => $this->adapter,
            'prompt' => $task->prompt,
            'model' => $this->model,
            'max_turns' => $task->maxTurns,
            'max_budget_usd' => $task->maxBudgetUsd,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            $result = $this->runWorkspaceCommand->handle(
                $workspace,
                [(string) config('builder.agents.runner.node'), (string) config('builder.agents.runner.path'), $taskFile],
                $task->timeoutSeconds,
                array_filter($this->credentials, fn (string $value) => $value !== ''),
            );
        } finally {
            rescue(fn () => $this->runWorkspaceCommand->handle($workspace, ['rm', '-rf', self::TASK_DIRECTORY], 30), report: false);
        }

        return AgentOutcome::fromRunnerOutput($this->adapter, $this->provider, $this->model, $result->output, $result->timed_out);
    }
}
