<?php

namespace App\Runs\Agents;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\AgentTier;
use App\Models\Workspace;
use App\Runs\Contracts\CodingAgent;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\ModelGateway;
use App\Support\Secrets;
use App\Workspaces\WorkspaceManager;
use Closure;
use Illuminate\Support\Str;
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
     * @param  string|null  $strongModel  The stronger model for a repair the usual one could not finish, or null for none
     * @param  string|null  $strongEffort  How hard it thinks on strong tasks, or null to use $effort
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
        protected ?string $strongModel = null,
        protected ?string $strongEffort = null,
        protected ?ModelGateway $gateway = null,
    ) {}

    /**
     * Get the model that takes a task of the given tier.
     */
    public function modelFor(AgentTier $tier): ?string
    {
        return match ($tier) {
            AgentTier::Light => $this->lightModel ?? $this->model,
            AgentTier::Usual => $this->model,
            AgentTier::Strong => $this->strongModel ?? $this->model,
        };
    }

    /**
     * Get how hard the model thinks on a task of the given tier.
     */
    protected function effortFor(AgentTier $tier): ?string
    {
        return match ($tier) {
            AgentTier::Light => $this->lightEffort ?? $this->effort,
            AgentTier::Usual => $this->effort,
            AgentTier::Strong => $this->strongEffort ?? $this->effort,
        };
    }

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

        $credentials = array_filter($this->credentials, fn (string $value) => $value !== '');
        $opened = null;

        // With the gateway on, the agent gets a token that opens it for this
        // run in place of the key, so the key never enters the workspace.
        // The gateway also adds our working rules to each call, so the box
        // holds only the task. An agent signed in its own way, with no key,
        // is left as it is, and reads the rules with the task.
        $keyVariable = $this->gateway?->keyVariable($this->provider);
        $prompt = $task->prompt;

        if ($this->gateway?->enabled() && $keyVariable !== null && isset($credentials[$keyVariable])) {
            $opened = $this->gateway->open($this->provider, $task->timeoutSeconds, $task->instructions, $task->owner);
            $credentials = [...$credentials, ...$opened['environment']];
        } elseif ($keyVariable !== null && isset($credentials[$keyVariable]) && $workspace->driver !== 'local') {
            // A box runs the owner's code with a shell, so a real key must
            // not go in. Only a plain folder on this host may have one.
            throw new RuntimeException('The model gateway is off, so the agent would get the real key inside the workspace. Turn on BUILDER_MODEL_GATEWAY.');
        } elseif ($task->instructions !== null) {
            $prompt .= "\n\n".$task->instructions;
        }

        try {
            $this->workspaces->driver($workspace->driver)->writeFile((string) $workspace->driver_id, $taskFile, (string) json_encode([
                'adapter' => $this->adapter,
                // A key the owner pasted into a request or a note stays here.
                'prompt' => Secrets::redact($prompt),
                'model' => $this->modelFor($task->tier),
                'effort' => $this->effortFor($task->tier),
                'session' => $resume['session'] ?? null,
                'follow_up' => isset($resume['prompt']) ? Secrets::redact($resume['prompt']) : null,
                'max_turns' => $task->maxTurns,
                'max_budget_usd' => $task->maxBudgetUsd,
                'continue_only' => $resume['continue'] ?? false,
                'sandbox' => $this->sandbox,
                'protected_paths' => config('builder.construction.protected_paths', []),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $exception) {
            if ($opened !== null) {
                $this->gateway->close($opened['token']);
            }

            throw $exception;
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

        // A run lost with its runner left its progress behind, which names
        // the session it worked in.
        $lostSession = $result->lost ? self::leftBehind($this->workspaces, $workspace)['session'] ?? null : null;
        $this->removeTaskFiles($workspace);

        // The outcome names the model that took the task, so its work is
        // priced at that model's own rate.
        $outcome = AgentOutcome::fromRunnerOutput($this->adapter, $this->provider, $this->modelFor($task->tier), $result->output, $result->timed_out, $result->lost, $lostSession);

        // However the agent took the refusal, the run stops for the plan.
        return $opened !== null && $this->gateway?->refused($opened['token']) ? $outcome->stoppedForUsage() : $outcome;
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
     * Get the agent and session of a task whose runner never finished it,
     * from the progress it left in the workspace. Null when it left none.
     *
     * @return array{adapter: string, session: string}|null
     */
    public static function leftBehind(WorkspaceManager $workspaces, Workspace $workspace): ?array
    {
        $progress = json_decode((string) rescue(fn () => $workspaces->driver($workspace->driver)->readFile((string) $workspace->driver_id, self::TASK_DIRECTORY.'/progress.json'), null, report: false), true);

        return is_array($progress) && is_string($progress['adapter'] ?? null) && is_string($progress['session'] ?? null) && $progress['session'] !== ''
            ? ['adapter' => $progress['adapter'], 'session' => Str::limit($progress['session'], 200, '')]
            : null;
    }

    /**
     * Remove the task files, so they never become part of the change.
     */
    protected function removeTaskFiles(Workspace $workspace): void
    {
        rescue(fn () => $this->runWorkspaceCommand->handle($workspace, ['rm', '-rf', self::TASK_DIRECTORY], 30), report: false);
    }
}
