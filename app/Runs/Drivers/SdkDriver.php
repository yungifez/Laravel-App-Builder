<?php

namespace App\Runs\Drivers;

use App\Actions\Runs\ExtractCandidateChange;
use App\Actions\Runs\RecordModelUsage;
use App\Actions\Runs\RunCodingAgent;
use App\Actions\Runs\WriteBrief;
use App\Actions\Workspaces\RunWorkspaceCommand;
use App\Enums\AgentOutcomeStatus;
use App\Enums\ModelRole;
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
        protected ExtractCandidateChange $extractCandidateChange,
        protected WriteBrief $writeBrief,
    ) {
        parent::__construct($acceptanceSelector, $recordModelUsage);
    }

    public function build(Run $run, Plan $plan, ToolSession $tools): string
    {
        $workspace = $run->workspace ?? throw new ConstructionFailed(__('The run has no workspace.'));

        // A background tidy-up goes to the light model first, on a smaller budget.
        $light = ($run->featureRequest->tidy['tier'] ?? null) === 'light';

        $outcome = $this->runCodingAgent->handle($run, $tools->lease(), $workspace, new AgentTask(
            prompt: $this->writeBrief->handle($run, $plan),
            maxTurns: (int) config('builder.agents.max_turns'),
            maxBudgetUsd: (float) ($light ? config('builder.verification.shortcuts.tidy.max_budget_usd') : config('builder.agents.max_budget_usd')),
            timeoutSeconds: (int) config('builder.construction.budgets.minutes') * 60,
            light: $light,
            resume: $this->resumeFor($run),
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

    /**
     * For a repair pass, get the session the change was built in and what
     * to tell the agent there: only the problems, since it already has the
     * brief. Most of a pass's cost is the agent reading the app, and a
     * continued session has read it already.
     *
     * @return array{adapter: string, session: string, prompt: string}|null
     */
    protected function resumeFor(Run $run): ?array
    {
        if ($run->feedback === null) {
            return null;
        }

        $data = $this->lastBuild($run)->data ?? [];
        $followUp = $this->writeBrief->followUp($run);

        if (! is_string($data['adapter'] ?? null) || ! is_string($data['session'] ?? null) || $followUp === null) {
            return null;
        }

        return ['adapter' => $data['adapter'], 'session' => $data['session'], 'prompt' => $followUp];
    }

    /**
     * Review with the other provider from the one that built the change.
     * When that provider has no credentials, the default reviewer is used
     * and the run log says the review was not independent.
     */
    public function review(Run $run, ReviewEvidence $evidence): Review
    {
        $builtBy = $this->builtBy($run);
        $reviewer = config("builder.agents.reviewers.{$builtBy}");
        $provider = is_array($reviewer) && is_string($reviewer['provider'] ?? null) ? $reviewer['provider'] : null;

        if ($provider === null || blank(config("ai.providers.{$provider}.key"))) {
            $run->recordEvent('reviewer_not_independent', [
                'built_by' => $builtBy,
                'wanted' => $provider,
                'reason' => $provider === null ? 'no_reviewer_configured' : 'no_credentials',
            ]);

            return parent::review($run, $evidence);
        }

        $model = $reviewer['model'] ?? null;

        // A review on the default reviewer beats no review when the other
        // provider cannot serve it; the switch is logged.
        return $this->reviewWith(
            $run,
            $evidence,
            [$provider => is_string($model) && $model !== '' ? $model : null] + ModelRole::Reviewer->providers(),
        );
    }

    /**
     * Get the provider that built the run's latest change.
     */
    protected function builtBy(Run $run): ?string
    {
        $provider = $this->lastBuild($run)?->data['provider'] ?? null;

        return is_string($provider) ? $provider : null;
    }

    /**
     * Get the log entry of the latest agent attempt that built the change.
     */
    protected function lastBuild(Run $run): ?RunEvent
    {
        /** @var RunEvent|null */
        return $run->events()
            ->where('type', 'model_call')
            ->where('data->role', 'coder')
            ->where('data->status', AgentOutcomeStatus::Completed->value)
            ->latest('sequence')
            ->first();
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

        $baseline = $this->extractCandidateChange->baseline($workspace);

        // Against the recorded baseline, so commits the agent made do not
        // count as the original: remove what is there, put the baseline back.
        foreach ($protected as $path) {
            $this->runWorkspaceCommand->handle($workspace, ['git', 'rm', '-rqf', '--ignore-unmatch', '--', $path], 60);
            $this->runWorkspaceCommand->handle($workspace, ['git', 'checkout', '-q', $baseline, '--', $path], 60);
            $this->runWorkspaceCommand->handle($workspace, ['git', 'clean', '-fdq', '--', $path], 60);
        }
    }
}
