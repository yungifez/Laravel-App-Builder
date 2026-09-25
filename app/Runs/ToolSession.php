<?php

namespace App\Runs;

use App\Models\Run;

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
     */
    public function call(string $operationKey, string $tool, array $arguments = [], ?int $expectedRevision = null): OperationResult
    {
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
}
