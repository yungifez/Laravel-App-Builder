<?php

namespace App\Runs\Drivers;

use App\Actions\Runs\RecordModelUsage;
use App\Actions\Runs\RunCodingAgent;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\AgentOutcomeStatus;
use App\Features\AcceptanceSelector;
use App\Models\Run;
use App\Models\RunEvent;
use App\Runs\Agents\AgentTask;
use App\Runs\Agents\CodingAgentManager;
use App\Runs\Exceptions\BudgetExhausted;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Plan;
use App\Runs\Review;
use App\Runs\ReviewEvidence;
use App\Runs\ToolSession;

/**
 * Plans and reviews like the agent driver, but builds with a coding agent
 * SDK working directly in the workspace: the Claude Agent SDK first, the
 * Codex SDK when Anthropic cannot serve the task. The reviewer always uses
 * the other provider from the one that built the change.
 */
class SdkDriver extends AgentDriver
{
    /**
     * The turn and budget limits an agent reports as having been reached.
     *
     * @var list<string>
     */
    protected const BUDGET_ERRORS = ['error_max_turns', 'error_max_budget_usd'];

    public function __construct(
        AcceptanceSelector $acceptanceSelector,
        RecordModelUsage $recordModelUsage,
        protected RunCodingAgent $runCodingAgent,
        protected CodingAgentManager $agents,
        protected RunWorkspaceCommand $runWorkspaceCommand,
    ) {
        parent::__construct($acceptanceSelector, $recordModelUsage);
    }

    public function build(Run $run, Plan $plan, ToolSession $tools): string
    {
        $workspace = $run->workspace ?? throw new ConstructionFailed(__('The run has no workspace.'));

        $outcome = $this->runCodingAgent->handle($run, $tools->lease(), $workspace, new AgentTask(
            prompt: $this->buildPrompt($run, $plan)."\n\n".$this->workingRules(),
            maxTurns: (int) config('builder.agents.max_turns'),
            maxBudgetUsd: (float) config('builder.agents.max_budget_usd'),
            timeoutSeconds: (int) config('builder.construction.budgets.minutes') * 60,
        ));

        $this->restoreProtectedPaths($run);

        if ($outcome->status === AgentOutcomeStatus::Failed) {
            if (in_array($outcome->errorKind, self::BUDGET_ERRORS, true)) {
                throw new BudgetExhausted(__('The agent used up its turns or budget before finishing.'));
            }

            throw new ConstructionFailed(__('The agent could not make the change: :reason', ['reason' => $outcome->error ?? $outcome->errorKind]));
        }

        return (string) $outcome->summary;
    }

    public function review(Run $run, ReviewEvidence $evidence): Review
    {
        $builtBy = $this->builtBy($run);
        $reviewer = config("builder.agents.reviewers.{$builtBy}");

        if (! is_array($reviewer) || ! is_string($reviewer['provider'] ?? null)) {
            return parent::review($run, $evidence);
        }

        $model = $reviewer['model'] ?? null;

        return $this->reviewWith($run, $evidence, $reviewer['provider'], is_string($model) && $model !== '' ? $model : null);
    }

    /**
     * Get the provider that built the run's latest change.
     */
    protected function builtBy(Run $run): ?string
    {
        /** @var RunEvent|null $event */
        $event = $run->events()
            ->where('type', 'model_call')
            ->where('data->role', 'coder')
            ->where('data->status', AgentOutcomeStatus::Completed->value)
            ->latest('sequence')
            ->first();

        $provider = $event?->data['provider'] ?? null;

        return is_string($provider) ? $provider : null;
    }

    /**
     * Put back anything the agent changed under a protected path, such as
     * the platform's acceptance tests. Verification never trusts them anyway.
     */
    protected function restoreProtectedPaths(Run $run): void
    {
        $workspace = $run->workspace;

        if ($workspace === null) {
            return;
        }

        /** @var list<string> $protected */
        $protected = array_values(array_diff(config('builder.construction.protected_paths', []), ['.git']));

        foreach ($protected as $path) {
            $this->runWorkspaceCommand->handle($workspace, ['git', 'checkout', '-q', 'HEAD', '--', $path], 60);
            $this->runWorkspaceCommand->handle($workspace, ['git', 'clean', '-fdq', '--', $path], 60);
        }
    }

    /**
     * How an SDK agent should work, besides the brief.
     */
    protected function workingRules(): string
    {
        return <<<'RULES'
        ## How to work

        You are working in the application's repository. Follow its AGENTS.md and Laravel's conventions. Add or update feature tests for the behaviour you build, run them with `php artisan test`, and fix failures. Never change tests/Acceptance, .env, vendor or .git: those changes are thrown away. Keep the notes in .builder/ up to date as described in AGENTS.md or, if it says nothing, by updating the notes of the areas you change.

        When you are done, reply with a short summary of what you changed. Your summary is not taken as proof: the change is verified and reviewed independently.
        RULES;
    }
}
