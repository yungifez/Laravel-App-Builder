<?php

namespace App\Runs;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\OperationStatus;
use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunOperation;
use App\Runs\Contracts\MutatingTool;
use App\Runs\Contracts\Tool;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use App\Runs\Exceptions\ToolFailed;
use App\Runs\Exceptions\ToolRejected;
use App\Workspaces\WorkspaceManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Runs tool calls for a run's writer, on the server's terms.
 *
 * Every call names an operation key. The call is journaled before the tool
 * runs, so repeating a key returns the recorded outcome instead of acting
 * twice, and a call whose outcome was lost (the worker died mid-call) is
 * reconciled against the workspace before anything runs again. Calls must
 * come from the current lease holder, while the run is implementing and
 * within its budget; changes must name the workspace revision they expect.
 */
class ToolExecutor
{
    public function __construct(
        protected Container $container,
        protected WorkspaceManager $workspaces,
        protected RunWorkspaceCommand $runWorkspaceCommand,
    ) {}

    /**
     * Execute a tool call for the lease holder.
     *
     * Refusals (unknown tool, invalid arguments, protected paths, stale
     * revisions or contents) come back as rejected results for the caller to
     * act on. Only conditions that end the caller's turn are thrown.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws LeaseLost when the caller no longer holds the run.
     * @throws RunCancelled when the owner cancelled the run.
     * @throws BudgetExhausted when the run is out of operations or time.
     */
    public function execute(RunLease $lease, string $operationKey, string $tool, array $arguments = [], ?int $expectedRevision = null): OperationResult
    {
        $claim = DB::transaction(fn () => $this->claim($lease, $operationKey, $tool, $arguments, $expectedRevision));

        if ($claim instanceof OperationResult) {
            return $claim;
        }

        $run = Run::query()->findOrFail($lease->runId);
        $context = $this->context($run);
        $handler = $this->tool($tool);

        try {
            $result = null;

            if (! $claim->wasRecentlyCreated && $handler instanceof MutatingTool) {
                $result = $handler->reconcile($context, $arguments);
            }

            $result ??= $handler->handle($context, $arguments);

            return $this->settle($lease, $claim, OperationStatus::Succeeded, $result);
        } catch (ToolRejected $exception) {
            return $this->settle($lease, $claim, OperationStatus::Rejected, error: $exception->getMessage());
        } catch (ToolFailed $exception) {
            return $this->settle($lease, $claim, OperationStatus::Failed, error: $exception->getMessage());
        }
    }

    /**
     * Get the names of the tools callers may use.
     *
     * @return list<string>
     */
    public function tools(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * Journal the call under the run's row lock, or answer it without running
     * the tool (a replay or a refusal).
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function claim(RunLease $lease, string $operationKey, string $tool, array $arguments, ?int $expectedRevision): RunOperation|OperationResult
    {
        $run = Run::query()->lockForUpdate()->findOrFail($lease->runId);

        $lease->assertHeldOn($run);

        if ($run->status === RunStatus::Cancelling) {
            throw RunCancelled::forRun($run->id);
        }

        $payloadHash = $this->payloadHash($tool, $arguments);
        $existing = $run->operations()->where('operation_key', $operationKey)->first();

        if ($existing !== null) {
            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                return new OperationResult(OperationStatus::Rejected, [], __('The operation key [:key] was already used for a different call.', ['key' => $operationKey]), $run->workspace_revision);
            }

            if ($existing->status->finished()) {
                return OperationResult::fromOperation($existing, $run->workspace_revision, replayed: true);
            }

            // The earlier holder died before recording the outcome: take the
            // entry over and reconcile it before running anything.
            $existing->update(['fencing_token' => $lease->fencingToken]);
            $run->extendLease();
            $run->recordEvent('operation_reconciling', ['key' => $operationKey, 'tool' => $tool]);

            return $existing;
        }

        if ($run->status !== RunStatus::Implementing) {
            return new OperationResult(OperationStatus::Rejected, [], __('Tools can only be used while the run is implementing.'), $run->workspace_revision);
        }

        $this->ensureWithinBudget($run);

        $operation = $run->operations()->create([
            'operation_key' => $operationKey,
            'tool' => $tool,
            'arguments' => $arguments,
            'payload_hash' => $payloadHash,
            'fencing_token' => $lease->fencingToken,
            'expected_revision' => $expectedRevision,
            'status' => OperationStatus::Pending,
            'started_at' => now(),
        ]);

        $refusal = $this->refusal($run, $operation, $arguments, $expectedRevision);

        if ($refusal !== null) {
            $operation->update(['status' => OperationStatus::Rejected, 'error' => $refusal, 'finished_at' => now()]);
            $run->recordEvent('operation', ['key' => $operationKey, 'tool' => $tool, 'status' => OperationStatus::Rejected->value, 'error' => $refusal]);

            return OperationResult::fromOperation($operation, $run->workspace_revision);
        }

        $run->extendLease();

        return $operation;
    }

    /**
     * Explain why the call may not run, or return null when it may.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function refusal(Run $run, RunOperation $operation, array $arguments, ?int $expectedRevision): ?string
    {
        $tool = $operation->tool;

        if (! array_key_exists($tool, $this->definitions())) {
            return __('Unknown tool [:tool].', ['tool' => $tool]);
        }

        $handler = $this->tool($tool);
        $validator = Validator::make($arguments, $handler->rules());

        if ($validator->fails()) {
            return implode(' ', $validator->errors()->all());
        }

        if ($handler instanceof MutatingTool) {
            if ($expectedRevision !== $run->workspace_revision) {
                return __('The workspace is at revision :current, not :expected; read the files again before changing them.', [
                    'current' => $run->workspace_revision,
                    'expected' => $expectedRevision ?? '(none)',
                ]);
            }

            $pending = $run->operations()
                ->whereKeyNot($operation->id)
                ->where('status', OperationStatus::Pending)
                ->whereIn('tool', $this->mutatingTools())
                ->exists();

            if ($pending) {
                return __('Another change to the workspace is still in progress.');
            }
        }

        return null;
    }

    /**
     * Stop the run once it has used its tool operations or its time.
     *
     * @throws BudgetExhausted
     */
    protected function ensureWithinBudget(Run $run): void
    {
        $operations = (int) config('builder.construction.budgets.operations');
        $minutes = (int) config('builder.construction.budgets.minutes');

        if ($run->operations()->count() >= $operations) {
            throw new BudgetExhausted(__('The run used all :count of its tool operations.', ['count' => $operations]));
        }

        if ($run->started_at !== null && $run->started_at->copy()->addMinutes($minutes)->isPast()) {
            throw new BudgetExhausted(__('The run used all :count minutes of its time.', ['count' => $minutes]));
        }
    }

    /**
     * Record the tool's outcome, if the caller still holds the run.
     *
     * When the lease was lost while the tool ran, the entry stays pending and
     * the next holder reconciles it.
     *
     * @param  array<string, mixed>  $result
     *
     * @throws LeaseLost
     */
    protected function settle(RunLease $lease, RunOperation $operation, OperationStatus $status, array $result = [], ?string $error = null): OperationResult
    {
        return DB::transaction(function () use ($lease, $operation, $status, $result, $error) {
            $run = Run::query()->lockForUpdate()->findOrFail($lease->runId);

            $lease->assertHeldOn($run);

            $operation->update([
                'status' => $status,
                'result' => $result,
                'error' => $error,
                'finished_at' => now(),
            ]);

            if ($status === OperationStatus::Succeeded && $this->tool($operation->tool) instanceof MutatingTool) {
                $run->workspace_revision++;
            }

            $run->lease_expires_at = now()->addSeconds((int) config('builder.construction.lease_seconds'));
            $run->save();

            $run->recordEvent('operation', array_filter([
                'key' => $operation->operation_key,
                'tool' => $operation->tool,
                'status' => $status->value,
                'error' => $error,
                'revision' => $run->workspace_revision,
            ], fn ($value) => $value !== null));

            return OperationResult::fromOperation($operation, $run->workspace_revision);
        });
    }

    /**
     * Build the context tools run in: the run's own workspace only.
     */
    protected function context(Run $run): ToolContext
    {
        $workspace = $run->workspace ?? throw new ToolFailed(__('The run has no workspace.'));

        /** @var list<string> $protectedPaths */
        $protectedPaths = config('builder.construction.protected_paths', []);

        return new ToolContext(
            run: $run,
            workspace: $workspace,
            driver: $this->workspaces->driver($workspace->driver),
            runWorkspaceCommand: $this->runWorkspaceCommand,
            protectedPaths: $protectedPaths,
        );
    }

    /**
     * Get the names of the configured tools that change the workspace.
     *
     * @return list<string>
     */
    protected function mutatingTools(): array
    {
        return array_keys(array_filter($this->definitions(), fn (string $class) => is_subclass_of($class, MutatingTool::class)));
    }

    /**
     * Resolve a configured tool.
     */
    protected function tool(string $name): Tool
    {
        /** @var Tool */
        return $this->container->make($this->definitions()[$name]);
    }

    /**
     * Get the configured tools, keyed by name.
     *
     * @return array<string, class-string<Tool>>
     */
    protected function definitions(): array
    {
        /** @var array<string, class-string<Tool>> */
        return (array) config('builder.construction.tools');
    }

    /**
     * Hash the call so a reused key with a different payload is detected.
     *
     * The expected revision is a precondition checked when the call first
     * runs, not part of the payload, so a resumed caller can replay the key.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function payloadHash(string $tool, array $arguments): string
    {
        return hash('sha256', json_encode([$tool, $this->canonical($arguments)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Sort keys recursively so equal payloads hash equally.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    protected function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
