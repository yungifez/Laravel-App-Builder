<?php

namespace App\Runs;

use App\Models\Run;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\LeaseLost;
use App\Runs\Exceptions\RunCancelled;

/**
 * A construction driver's handle on the tools, bound to its run and lease.
 */
class ToolSession
{
    protected int $revision;

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
        $result = $this->executor->execute($this->lease, $operationKey, $tool, $arguments, $expectedRevision);

        $this->revision = $result->revision;

        return $result;
    }

    /**
     * Get the lease of the worker running the session.
     */
    public function lease(): RunLease
    {
        return $this->lease;
    }

    /**
     * Get the workspace revision as of the latest call.
     */
    public function revision(): int
    {
        return $this->revision;
    }
}
