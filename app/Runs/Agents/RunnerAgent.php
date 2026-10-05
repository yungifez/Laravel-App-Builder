<?php

namespace App\Runs\Agents;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Models\Workspace;
use App\Runs\Contracts\CodingAgent;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\ModelGateway;
use App\Support\Secrets;
use App\Workspaces\WorkspaceManager;
use Closure;
use RuntimeException;
use Throwable;

/**
 * Runs an agent SDK through the Node runner (resources/agent-runner) inside
 * the workspace. The credentials reach only the runner's process, and are
 * never written to the workspace or the command log.
 */
class RunnerAgent implements CodingAgent
{
    /**
     * Where the task file goes while the agent runs: inside .git, so it is
     * never part of the change, with a name that says nothing about who
     * wrote it. It is removed afterwards.
     */
    public const TASK_DIRECTORY = '.git/agent-task';

    /**
     * @param  array<string, string>  $credentials  Environment variables for the runner
     * @param  string|null  $sandbox  The agent's own sandbox mode, when it has one
     * @param  string|null  $lightModel  The cheaper model for light tasks, or null to use the usual one
     * @param  string|null  $effort  How hard the model thinks, or null for its default
     * @param  string|null  $lightEffort  How hard it thinks on light tasks, or null to use $effort
     */
    public function __construct(
        protected string $adapter,
        protected string $provider,
        protected ?string $model,
        protected array $credentials,
        protected WorkspaceManager $workspaces,
        protected RunWorkspaceCommand $runWorkspaceCommand,
        protected ?string $sandbox = null,
        protected ?string $lightModel = null,
        protected ?string $effort = null,
        protected ?string $lightEffort = null,
        protected ?ModelGateway $gateway = null,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function run(Workspace $workspace, AgentTask $task, ?Closure $whileRunning = null): AgentOutcome
    {
        $taskFile = self::TASK_DIRECTORY.'/task.json';

        // A repair pass continues this agent's own earlier session, so it
        // does not read the whole app again. Another agent's session is no
        // use to it, so it starts fresh with the whole prompt.
        $resume = $task->resume !== null && $task->resume['adapter'] === $this->adapter ? $task->resume : null;

        $this->workspaces->driver($workspace->driver)->writeFile((string) $workspace->driver_id, $taskFile, (string) json_encode([
            'adapter' => $this->adapter,
            // A key the owner pasted into a request or a note stays here.
            'prompt' => Secrets::redact($task->prompt),
            'model' => $task->light ? ($this->lightModel ?? $this->model) : $this->model,
            'effort' => $task->light ? ($this->lightEffort ?? $this->effort) : $this->effort,
            'session' => $resume['session'] ?? null,
            'follow_up' => isset($resume['prompt']) ? Secrets::redact($resume['prompt']) : null,
            'max_turns' => $task->maxTurns,
            'max_budget_usd' => $task->maxBudgetUsd,
            'sandbox' => $this->sandbox,
            'protected_paths' => config('builder.construction.protected_paths', []),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $credentials = array_filter($this->credentials, fn (string $value) => $value !== '');
        $opened = null;

        // With the gateway on, the agent gets a token that opens it for this
        // run in place of the key, so the key never enters the workspace.
        // An agent signed in its own way, with no key, is left as it is.
        $keyVariable = $this->gateway?->keyVariable($this->provider);

        if ($this->gateway?->enabled() && $keyVariable !== null && isset($credentials[$keyVariable])) {
            $opened = $this->gateway->open($this->provider, $task->timeoutSeconds);
            $credentials = [...$credentials, ...$opened['environment']];
        } elseif ($keyVariable !== null && isset($credentials[$keyVariable]) && $workspace->driver !== 'local') {
            // A box runs the owner's code with a shell, so a real key must
            // not go in. Only a plain folder on this host may have one.
            $this->removeTaskFiles($workspace);

            throw new RuntimeException('The model gateway is off, so the agent would get the real key inside the workspace. Turn on BUILDER_MODEL_GATEWAY.');
        }

        try {
            $result = $this->runWorkspaceCommand->handle(
                $workspace,
                [(string) config('builder.agents.runner.node'), $this->runnerPath($workspace), $taskFile],
                $task->timeoutSeconds,
                $credentials,
                $whileRunning,
            );
        } catch (LeaseLost $exception) {
            // The workspace may already belong to another worker, so its
            // task files are left alone.
            throw $exception;
        } catch (Throwable $exception) {
            $this->removeTaskFiles($workspace);

            throw $exception;
        } finally {
            // The token dies with the run, even if the agent kept a copy.
            if ($opened !== null) {
                $this->gateway?->close($opened['token']);
            }
        }

        $this->removeTaskFiles($workspace);

        return AgentOutcome::fromRunnerOutput($this->adapter, $this->provider, $this->model, $result->output, $result->timed_out, $result->lost);
    }

    /**
     * Get where the Node runner is for the workspace's driver: a box has its
     * own copy, while the other drivers use the control plane's.
     */
    protected function runnerPath(Workspace $workspace): string
    {
        return (string) (config("workspaces.drivers.{$workspace->driver}.agent_runner") ?? config('builder.agents.runner.path'));
    }

    /**
     * Remove the task files, so they never become part of the change.
     */
    protected function removeTaskFiles(Workspace $workspace): void
    {
        rescue(fn () => $this->runWorkspaceCommand->handle($workspace, ['rm', '-rf', self::TASK_DIRECTORY], 30), report: false);
    }
}
