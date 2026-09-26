<?php

namespace Tests\Fakes;

use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;
use App\Runs\Contracts\CodingAgent;
use Closure;

/**
 * A coding agent whose behaviour a test scripts: it may change the workspace
 * and returns the outcome the test decides.
 */
class FakeCodingAgent implements CodingAgent
{
    /** @var list<AgentTask> */
    public array $tasks = [];

    /**
     * @param  Closure(Workspace, AgentTask): AgentOutcome  $behaviour
     */
    public function __construct(
        protected string $provider,
        protected Closure $behaviour,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function run(Workspace $workspace, AgentTask $task): AgentOutcome
    {
        $this->tasks[] = $task;

        return ($this->behaviour)($workspace, $task);
    }
}
