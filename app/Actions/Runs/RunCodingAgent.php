<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\AgentOutcomeStatus;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\ProvidersUnavailable;
use App\Runs\RunLease;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RunCodingAgent
{
    public function __construct(
        private CodingAgentManager $agents,
        private RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    /**
     * Run the task with the first agent whose provider can serve it.
     *
     * Failing over happens only on provider trouble. The workspace is put
     * back exactly as it was before the first attempt, and the same task goes
     * to the next agent; partial edits never carry across. An agent whose
     * provider keeps failing is tried last for a while (a circuit breaker).
     * Every attempt is logged with what it cost.
     *
     * @throws ProvidersUnavailable when no provider could serve the task.
     * @throws ConstructionFailed
     * @throws LeaseLost
     */
    public function handle(Run $run, RunLease $lease, Workspace $workspace, AgentTask $task): AgentOutcome
    {
        $snapshot = $this->snapshot($workspace);
        $previous = null;

        foreach ($this->order() as $adapter) {
            if ($previous !== null) {
                $this->restore($workspace, $snapshot);
                $this->recordEvent($run, $lease, 'failover', [
                    'from' => $previous->adapter,
                    'to' => $adapter,
                    'reason' => $previous->errorKind,
                ]);
            }

            $outcome = $this->agents->driver($adapter)->run($workspace, $task);

            $this->recordEvent($run, $lease, 'model_call', [
                'role' => 'coder',
                ...$outcome->toArray(),
            ]);

            if ($outcome->status !== AgentOutcomeStatus::ProviderUnavailable) {
                Cache::forget($this->circuitKey($adapter));

                return $outcome;
            }

            $this->recordProviderFailure($adapter);
            $previous = $outcome;
        }

        $this->restore($workspace, $snapshot);

        throw new ProvidersUnavailable(__('No AI provider could take the task right now (:reason). Try again later.', [
            'reason' => $previous->error ?? $previous->errorKind ?? 'unknown',
        ]));
    }

    /**
     * Get the agents in the order to try them: configured order, with agents
     * whose circuit is open moved to the end.
     *
     * @return list<string>
     */
    protected function order(): array
    {
        $threshold = (int) config('builder.agents.circuit.failures');
        $open = fn (string $adapter) => (int) Cache::get($this->circuitKey($adapter), 0) >= $threshold;
        $order = $this->agents->order();

        return [
            ...array_values(array_filter($order, fn (string $adapter) => ! $open($adapter))),
            ...array_values(array_filter($order, $open)),
        ];
    }

    /**
     * Count a provider failure towards the agent's circuit.
     */
    protected function recordProviderFailure(string $adapter): void
    {
        $key = $this->circuitKey($adapter);

        Cache::add($key, 0, now()->addMinutes((int) config('builder.agents.circuit.minutes')));
        Cache::increment($key);
    }

    protected function circuitKey(string $adapter): string
    {
        return "builder:agents:{$adapter}:provider-failures";
    }

    /**
     * Record the workspace's current files as a git tree, without changing
     * the files or the baseline commit.
     *
     * @throws ConstructionFailed
     */
    protected function snapshot(Workspace $workspace): string
    {
        $this->git($workspace, ['git', 'add', '--all']);

        return trim($this->git($workspace, ['git', 'write-tree']));
    }

    /**
     * Put the workspace's files back to a snapshot, removing anything added
     * since (ignored files, such as dependencies, stay).
     *
     * @throws ConstructionFailed
     */
    protected function restore(Workspace $workspace, string $tree): void
    {
        $this->git($workspace, ['git', 'read-tree', '--reset', '-u', $tree]);
        $this->git($workspace, ['git', 'clean', '-fdq']);
    }

    /**
     * Run a git command in the workspace and return its output.
     *
     * @param  list<string>  $command
     *
     * @throws ConstructionFailed
     */
    protected function git(Workspace $workspace, array $command): string
    {
        $result = $this->runWorkspaceCommand->handle($workspace, $command, 120);

        if ($result->exit_code !== 0 || $result->timed_out) {
            throw new ConstructionFailed(__('The workspace could not be prepared for the agent. :reason', ['reason' => trim($result->error_output)]));
        }

        return $result->output;
    }

    /**
     * Log an event while the lease still holds the run.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LeaseLost
     */
    protected function recordEvent(Run $run, RunLease $lease, string $type, array $data): void
    {
        DB::transaction(function () use ($run, $lease, $type, $data) {
            $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

            $lease->assertHeldOn($locked);

            $locked->recordEvent($type, $data);
        });
    }
}
