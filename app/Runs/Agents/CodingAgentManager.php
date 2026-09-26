<?php

namespace App\Runs\Agents;

use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Runs\Contracts\CodingAgent;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Manager;

/**
 * The coding agents a run may use, in config/builder.php "agents".
 *
 * @method CodingAgent driver(string|null $driver = null)
 */
class CodingAgentManager extends Manager
{
    /**
     * Get the first agent in the configured order.
     */
    public function getDefaultDriver(): string
    {
        return (string) ($this->order()[0] ?? 'claude');
    }

    /**
     * Get the agents in the order they are tried.
     *
     * @return list<string>
     */
    public function order(): array
    {
        /** @var list<string> */
        return $this->config->get('builder.agents.order', []);
    }

    /**
     * Get the provider that serves an agent.
     */
    public function providerOf(string $adapter): string
    {
        return (string) $this->config->get("builder.agents.adapters.{$adapter}.provider");
    }

    /**
     * Create the Claude Agent SDK agent.
     */
    public function createClaudeDriver(): CodingAgent
    {
        return $this->runner('claude', ['ANTHROPIC_API_KEY' => (string) $this->config->get('ai.providers.anthropic.key')]);
    }

    /**
     * Create the Codex SDK agent.
     */
    public function createCodexDriver(): CodingAgent
    {
        return $this->runner('codex', ['OPENAI_API_KEY' => (string) $this->config->get('ai.providers.openai.key')]);
    }

    /**
     * Create an agent that runs through the Node runner.
     *
     * @param  array<string, string>  $credentials
     */
    protected function runner(string $adapter, array $credentials): CodingAgent
    {
        $model = $this->config->get("builder.agents.adapters.{$adapter}.model");

        return new RunnerAgent(
            $adapter,
            $this->providerOf($adapter),
            is_string($model) && $model !== '' ? $model : null,
            $credentials,
            $this->container->make(WorkspaceManager::class),
            $this->container->make(RunWorkspaceCommand::class),
        );
    }
}
