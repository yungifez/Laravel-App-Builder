<?php

namespace App\Runs;

use App\Models\Run;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;
use Throwable;

/**
 * A construction driver's handle on the tools, bound to its run and lease.
 *
 * Model tool loops catch tool exceptions and hand them back to the model, so
 * a condition that must end the run (a lost lease, a cancellation, an
 * exhausted budget) is recorded here as a halt instead: every later call is
 * refused without running, and the driver rethrows it once the loop returns.
 */
class ToolSession
{
    protected int $revision;

    protected ?Throwable $halt = null;

    public function __construct(
        protected ToolExecutor $executor,
        public readonly Run $run,
        protected RunLease $lease,
    ) {
        $this->revision = $run->workspace_revision;
    }

    /**
     * Call a tool. Pass the workspace revision the change is based on for
     * tools that change the workspace.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws LeaseLost
     * @throws RunCancelled
     * @throws BudgetExhausted
     */
    public function call(string $operationKey, string $tool, array $arguments = [], ?int $expectedRevision = null): OperationResult
    {
        $this->throwIfHalted();

        $result = $this->executor->execute($this->lease, $operationKey, $tool, $arguments, $expectedRevision);

        $this->revision = $result->revision;

        return $result;
    }

    /**
     * Get the workspace revision as of the latest call.
     */
    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * Get the names of the tools the driver may call.
     *
     * @return list<string>
     */
    public function tools(): array
    {
        return $this->executor->tools();
    }

    /**
     * Stop the session: no further calls run, and the reason is rethrown.
     */
    public function halt(LeaseLost|RunCancelled|BudgetExhausted $reason): void
    {
        $this->halt ??= $reason;
    }

    /**
     * Determine if the session has stopped.
     */
    public function halted(): bool
    {
        return $this->halt !== null;
    }

    /**
     * Rethrow the reason the session stopped, if it has.
     *
     * @throws LeaseLost
     * @throws RunCancelled
     * @throws BudgetExhausted
     */
    public function throwIfHalted(): void
    {
        if ($this->halt !== null) {
            throw $this->halt;
        }
    }
}
