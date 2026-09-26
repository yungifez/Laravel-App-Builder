<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\HaltWhenRunStops;
use App\Ai\Tools\WorkspaceTool;
use App\Ai\Tools\WorkspaceTools;
use App\Runs\ToolSession;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Carries out a saved plan in the run's workspace, only through the run's
 * server-side tools. A call to a tool that does not exist is reported back to
 * the model instead of ending the run.
 */
#[RepairToolCalls]
#[Timeout(300)]
class FeatureCoder implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    public function __construct(
        protected ToolSession $session,
        protected string $operationPrefix,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are a careful Laravel developer making one planned change to an existing application.

        Work only through the tools. Every tool result is JSON with a status (succeeded, failed, rejected or stopped) and the current workspace_revision.
        - Look before you change: list, search and read the files you will touch.
        - To replace a file, pass the sha256 from your latest read_file of it. To create a file, pass null. Always pass the workspace_revision from your latest tool result.
        - If a call is rejected, read the error, re-read what changed, and try again with a new call. Do not repeat the same call.
        - Prefer apply_patch for small edits to large files, and write_file for new files.
        - Follow the project's conventions (AGENTS.md if present) and Laravel's defaults. Add or update feature tests for the behaviour you build.
        - Never change tests/Acceptance, .env, vendor or .git: the platform refuses it.
        - Run the tests with run_command when your change is complete, and fix failures.
        - The application describes itself in .builder/: project.md and one file per area in .builder/capabilities/. When your change alters what an area does, update that file's notes in plain language, and its paths when you add code for it. When you find that the area affects another one, add an effect with source: agent and a one-line reason. Never remove what the owner wrote unless the request changes it.
        - If status is stopped, stop at once.

        You have a limited number of tool calls. When you are done, reply with a short summary of what you changed. Your summary is not taken as proof: the change is verified and reviewed independently.
        INSTRUCTIONS;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return list<WorkspaceTool>
     */
    public function tools(): iterable
    {
        return WorkspaceTools::for($this->session, $this->operationPrefix);
    }

    /**
     * Get the middleware wrapping each generation step of the agent.
     *
     * @return list<HaltWhenRunStops>
     */
    public function middleware(): array
    {
        return [new HaltWhenRunStops($this->session)];
    }

    /**
     * Get the maximum number of model steps: the run's operation budget plus
     * room for the final summary. The budget itself is enforced by the tools.
     */
    public function maxSteps(): int
    {
        return (int) config('builder.construction.budgets.operations') + 2;
    }
}
