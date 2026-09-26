<?php

namespace App\Actions\Features;

use App\Enums\AgentOutcomeStatus;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Features\PatchSummary;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\RunEvent;
use App\Runs\Plan;

class DescribeFeatureRequest
{
    /**
     * Describe a change for a page that shows it: the request, its latest
     * run with its plan and review, the checks, the trial copy and the
     * follow-ups.
     *
     * @return array<string, mixed>
     */
    public function handle(FeatureRequest $featureRequest): array
    {
        $parent = $featureRequest->parent;

        return [
            'project' => $featureRequest->project->only('id', 'name'),
            'featureRequest' => [
                'id' => $featureRequest->id,
                'prompt' => $featureRequest->prompt,
                'status' => $featureRequest->status->value,
                'summary' => $featureRequest->summary,
                'error' => $featureRequest->error,
                'target_step' => $parent === null || $featureRequest->target_step === null
                    ? null
                    : $parent->step($featureRequest->target_step),
                'steps' => $featureRequest->steps ?? [],
                'files' => PatchSummary::files($featureRequest->patch),
                'commit_sha' => $featureRequest->commit_sha,
                'accepted_at' => $featureRequest->accepted_at?->toIso8601String(),
                'revert_sha' => $featureRequest->revert_sha,
                'reverted_at' => $featureRequest->reverted_at?->toIso8601String(),
                'can_accept' => $featureRequest->status === FeatureRequestStatus::Generated
                    && $featureRequest->commit_sha === null
                    && $featureRequest->latestRun?->status === RunStatus::Completed,
                'can_retry' => RetryFeatureRequest::retryable($featureRequest),
            ],
            'parent' => $parent?->only('id', 'prompt'),
            'verification' => $this->latestVerification($featureRequest),
            'run' => $this->latestRun($featureRequest),
            'preview' => $this->latestPreview($featureRequest),
            'followUps' => $featureRequest->followUps()->latest()->get()
                ->map(fn (FeatureRequest $followUp) => [
                    'id' => $followUp->id,
                    'prompt' => $followUp->prompt,
                    'status' => $followUp->status->value,
                    'target_step' => $followUp->target_step,
                ]),
        ];
    }

    /**
     * Get the latest verification run for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestVerification(FeatureRequest $featureRequest): ?array
    {
        $verification = $featureRequest->verifications()->latest('id')->first();

        return $verification === null ? null : [
            'id' => $verification->id,
            'status' => $verification->status->value,
            'results' => $verification->results ?? [],
            'error' => $verification->error,
            'started_at' => $verification->started_at?->toIso8601String(),
            'finished_at' => $verification->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Get the latest construction run and its log for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestRun(FeatureRequest $featureRequest): ?array
    {
        $run = $featureRequest->latestRun;

        return $run === null ? null : [
            'id' => $run->id,
            'status' => $run->status->value,
            'driver' => $run->driver,
            'error' => $run->error,
            'question' => $run->status === RunStatus::NeedsUserDecision ? $run->question : null,
            'answers' => $run->answers ?? [],
            'workspace_revision' => $run->workspace_revision,
            'plan' => $run->plan === null ? null : [
                'summary' => $run->plan['summary'],
                'acceptance_criteria' => $run->plan['acceptance_criteria'],
                'assumptions' => $run->plan['assumptions'],
                'understood_as' => $run->plan['understood_as'] ?? null,
                'current_behavior' => $run->plan['current_behavior'] ?? null,
                'preserve' => array_column(Plan::fromArray($run->plan)->preserve, 'statement'),
            ],
            'context' => $run->context === null ? null : [
                'mode' => $run->context['mode'],
                'targets' => $run->context['targets'],
                'included' => $run->context['included'],
                'tokens' => array_sum(array_column($run->context['included'], 'tokens')),
                'problems' => $run->context['problems'],
            ],
            'review' => $this->review($run),
            'built_by' => $this->builtBy($run),
            'repairs' => $run->repairs,
            'operations' => $run->operations()->count(),
            'budget' => [
                'operations' => (int) config('builder.construction.budgets.operations'),
                'minutes' => (int) config('builder.construction.budgets.minutes'),
                'repairs' => (int) config('builder.construction.budgets.repairs'),
            ],
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'events' => $run->events()->get()->map(fn (RunEvent $event) => [
                'sequence' => $event->sequence,
                'type' => $event->type,
                'data' => $event->data ?? [],
                'created_at' => $event->created_at?->toIso8601String(),
            ]),
        ];
    }

    /**
     * Get the coding agent that built the run's latest change, and whether
     * it was the backup because the first choice could not take the task.
     *
     * @return array{adapter: string, provider: string, model: string|null, backup: bool, reason: string|null}|null
     */
    protected function builtBy(Run $run): ?array
    {
        /** @var RunEvent|null $call */
        $call = $run->events()
            ->where('type', 'model_call')
            ->where('data->role', 'coder')
            ->where('data->status', AgentOutcomeStatus::Completed->value)
            ->latest('sequence')
            ->first();

        if ($call === null || ! is_string($call->data['adapter'] ?? null)) {
            return null;
        }

        /** @var RunEvent|null $failover */
        $failover = $run->events()->where('type', 'failover')->where('data->to', $call->data['adapter'])->latest('sequence')->first();
        $order = (array) config('builder.agents.order');

        return [
            'adapter' => $call->data['adapter'],
            'provider' => (string) ($call->data['provider'] ?? ''),
            'model' => isset($call->data['model']) && is_string($call->data['model']) ? $call->data['model'] : null,
            'backup' => $call->data['adapter'] !== ($order[0] ?? null),
            'reason' => is_string($failover?->data['reason'] ?? null) ? $failover->data['reason'] : null,
        ];
    }

    /**
     * Get the run's review for the owner: the behaviour changes and where the
     * change landed by area, with the areas' names.
     *
     * @return array<string, mixed>|null
     */
    protected function review(Run $run): ?array
    {
        if ($run->review === null) {
            return null;
        }

        $names = array_column($run->context['outline'] ?? [], 'name', 'key');
        $classification = $run->review['classification'];
        $areas = fn (array $files) => array_map(
            fn (string $key) => ['key' => $key, 'name' => $names[$key] ?? $key, 'files' => $files[$key]],
            array_keys($files),
        );

        return [
            'summary' => $run->review['summary'],
            'changes' => array_map(fn (array $change) => [
                ...$change,
                'area_name' => $change['area'] === null ? null : ($names[$change['area']] ?? $change['area']),
            ], $run->review['changes']),
            'areas' => [
                'requested' => $areas($classification['requested']),
                'may_also_affect' => $areas($classification['may_also_affect']),
                'unexpected' => $areas($classification['unexpected']),
            ],
            'preserved' => array_map(fn (array $item) => [
                ...$item,
                'area_name' => $item['area'] === null ? null : ($names[$item['area']] ?? $item['area']),
            ], $run->review['preserved'] ?? []),
            'verified' => $run->review['verified'] ?? [],
            'unclaimed' => $classification['unclaimed'],
            'context_updates' => $classification['context_updates'],
        ];
    }

    /**
     * Get the latest preview for the page.
     *
     * @return array<string, mixed>|null
     */
    protected function latestPreview(FeatureRequest $featureRequest): ?array
    {
        $preview = $featureRequest->previews()->latest('id')->first();

        return $preview === null ? null : [
            'id' => $preview->id,
            'status' => $preview->status->value,
            'error' => $preview->error,
            'url' => $preview->url(),
            'expires_at' => $preview->expires_at?->toIso8601String(),
        ];
    }
}
