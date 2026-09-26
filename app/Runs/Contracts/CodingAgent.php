<?php

namespace App\Runs\Contracts;

use App\Models\Workspace;
use App\Runs\Agents\AgentOutcome;
use App\Runs\Agents\AgentTask;

/**
 * A coding agent that works directly in a workspace (the Claude Agent SDK,
 * the Codex SDK) and reports how it ended.
 */
interface CodingAgent
{
    /**
     * Get the provider that serves the agent, for example "anthropic".
     */
    public function provider(): string;

    /**
     * Run the task in the workspace. Provider trouble is reported as an
     * outcome, never thrown.
     */
    public function run(Workspace $workspace, AgentTask $task): AgentOutcome;
}
