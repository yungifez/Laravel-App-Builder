<?php

namespace App\Actions\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\AgentOutcomeStatus;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\ProvidersUnavailable;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\RunLease;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Every attempt is logged with what it cost, and the attempt that stays
     * with what it did.
     *
     * While an agent works, its lease is renewed. When the lease is lost or
     * the owner cancels, the agent is stopped at once, so it never edits a
     * workspace another worker has taken over.
     *
     * @throws ProvidersUnavailable when no provider could serve the task.
     * @throws ConstructionFailed
     * @throws LeaseLost
     * @throws RunCancelled
     */
    public function handle(Run $run, RunLease $lease, Workspace $workspace, AgentTask $task): AgentOutcome
    {
        $this->ensureAgentsMayRunIn($workspace);

        $snapshot = $this->snapshot($workspace);
        $previous = null;

        foreach ($this->order($task->prefer) as $adapter) {
            if ($previous !== null) {
                $this->restore($workspace, $snapshot);
                $this->recordEvent($run, $lease, 'failover', [
                    'from' => $previous->adapter,
                    'to' => $adapter,
                    'reason' => $previous->errorKind,
                ]);
            }

            $outcome = $this->agents->driver($adapter)->run($workspace, $task, $this->heartbeat($run, $lease));

            // Claude's SDK reports what the session cost. Codex's does not, so
            // its tokens are priced from config when the model is known.
            $estimate = $outcome->costUsd === null && $outcome->model !== null
                ? RecordModelUsage::cost($outcome->model, $outcome->inputTokens, $outcome->outputTokens, $outcome->cachedInputTokens)
                : null;

            $this->recordEvent($run, $lease, 'model_call', [
                'role' => 'coder',
                ...$outcome->toArray(),
                // So a light repair that did not pass is not tried light again.
                'light' => $task->light,
                'cost_usd' => $outcome->costUsd ?? $estimate,
                'cost_source' => match (true) {
                    $outcome->costUsd !== null => 'reported',
                    $estimate !== null => 'estimated',
                    default => null,
                },
            ]);

            if ($outcome->status !== AgentOutcomeStatus::ProviderUnavailable) {
                Cache::forget($this->circuitKey($adapter));

                // What the agent did and said, kept for the owner to read
                // back once the task files are gone. Attempts that failed
                // over left nothing behind, so they tell no story.
                if ($outcome->story !== []) {
                    $this->recordEvent($run, $lease, 'agent_story', ['story' => $outcome->story]);
                }

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
     * Make the check an agent's command runs while it works: at most every
     * "heartbeat_seconds", renew the lease, or stop when it is lost or the
     * owner has cancelled.
     *
     * @return Closure(): void
     */
    protected function heartbeat(Run $run, RunLease $lease): Closure
    {
        $every = (int) config('builder.construction.heartbeat_seconds');
        $last = hrtime(true);

        return function () use ($run, $lease, $every, &$last) {
            if (hrtime(true) - $last < $every * 1_000_000_000) {
                return;
            }

            $last = hrtime(true);

            DB::transaction(function () use ($run, $lease) {
                $locked = Run::query()->lockForUpdate()->findOrFail($run->id);

                $lease->assertHeldOn($locked);

                if ($locked->status === RunStatus::Cancelling) {
                    throw RunCancelled::forRun($locked->id);
                }

                $locked->extendLease();
            });
        };
    }

    /**
     * Refuse a workspace driver that does not keep agents away from the
     * control plane, such as "local", unless an operator allowed it for
     * trusted apps. The owner only hears that the change could not start.
     *
     * @throws ConstructionFailed
     */
    protected function ensureAgentsMayRunIn(Workspace $workspace): void
    {
        if (config("workspaces.drivers.{$workspace->driver}.agents") !== false) {
            return;
        }

        Log::warning('A coding agent was refused in a workspace that does not isolate it.', [
            'driver' => $workspace->driver,
            'allow_with' => 'WORKSPACE_LOCAL_AGENTS=true (trusted apps only)',
        ]);

        throw new ConstructionFailed(__('This change could not be started here. Nothing in your app was changed.'));
    }

    /**
     * Get the agents in the order to try them: configured order, or the
     * preferred agent first, with agents whose circuit is open moved to the
     * end.
     *
     * @return list<string>
     */
    protected function order(?string $prefer = null): array
    {
        $threshold = (int) config('builder.agents.circuit.failures');
        $open = fn (string $adapter) => (int) Cache::get($this->circuitKey($adapter), 0) >= $threshold;
        $order = $this->agents->order();

        if ($prefer !== null && in_array($prefer, $order, true)) {
            $order = [$prefer, ...array_values(array_diff($order, [$prefer]))];
        }

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
