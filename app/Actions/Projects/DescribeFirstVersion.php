<?php

namespace App\Actions\Projects;

use App\Actions\Features\DescribeFeatureRequest;
use App\Actions\Features\ProposeFindings;
use App\Actions\Features\RetryFeatureRequest;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\FeatureRequest;
use App\Models\Project;

/**
 * Where the first version of an app started here stands, while the app has
 * none kept. Until then the app is only the template, so the workspace
 * shows this in place of the template's welcome page.
 */
class DescribeFirstVersion
{
    public function __construct(
        protected DescribeFeatureRequest $describeFeatureRequest,
        protected ProposeFindings $proposeFindings,
    ) {}

    /**
     * @return array{change: string, state: 'making'|'waiting'|'asking'|'ready'|'stopped', error: string|null, can_retry: bool, plan_ran_out: bool, checking: bool}|null
     */
    public function handle(Project $project): ?array
    {
        $change = $this->change($project);

        if ($change === null) {
            return null;
        }

        $described = $this->describeFeatureRequest->handle($change);
        $state = match (true) {
            $change->latestRun?->question !== null => 'asking',
            // A finding the checks asked the owner about is a question too;
            // another try would only skip it.
            $change->latestRun?->status === RunStatus::NeedsUserDecision
                && $change->latestRun->stop_reason === StopReason::FindingProposed
                && $this->proposeFindings->pending($change) !== [] => 'asking',
            // Made, then stopped in the checks or the review, as the chat
            // shows it.
            $described['featureRequest']['stopped'], RetryFeatureRequest::stoppedWhileChecking($change) => 'stopped',
            // Ready to try once made, as the list of changes offers it; the
            // checks may still run, and the pane says so.
            $change->status === FeatureRequestStatus::Generated => 'ready',
            // Handed to the owner's own tool, which has not asked for it yet:
            // nothing is being made until it does.
            $this->waitingForTheirTool($change) => 'waiting',
            default => 'making',
        };

        return [
            'change' => $change->uuid,
            'state' => $state,
            // Said as the chat says the stop: the run's own reason and
            // whose fault it is, not what was done to the run inside.
            'error' => $state === 'stopped' ? ($described['run']['error'] ?? $change->latestRun?->stop_reason?->said() ?? $described['featureRequest']['error'] ?? null) : null,
            'can_retry' => $state === 'stopped' && $described['featureRequest']['can_retry'],
            // Stopped because the month's AI use ran out: the next step is
            // the owner's plan, as in the chat.
            'plan_ran_out' => $state === 'stopped' && ($described['run']['plan_ran_out'] ?? false),
            'checking' => $state === 'ready' && ! $described['featureRequest']['can_accept'],
        ];
    }

    protected function waitingForTheirTool(FeatureRequest $change): bool
    {
        $run = $change->latestRun;

        if ($run?->driver !== 'worker' || $run->status !== RunStatus::Implementing) {
            return false;
        }

        $since = (int) $run->events()->where('type', 'status')->where('data->to', RunStatus::Implementing->value)->max('sequence');

        return ! $run->events()->whereIn('type', ['worker_query', 'worker_progress', 'worker_tried', 'worker_submitted'])->where('sequence', '>', $since)->exists();
    }

    /**
     * Whether the app still waits on its first version, in any state.
     */
    public function pending(Project $project): bool
    {
        return $this->change($project) !== null;
    }

    /**
     * The first version's change, while the app has none kept.
     */
    protected function change(Project $project): ?FeatureRequest
    {
        // An app brought in, or one with a kept change, is the owner's own.
        if (! $project->started_here || $project->featureRequests()->whereNotNull('accepted_at')->exists()) {
            return null;
        }

        // Tries again carry the same words, so the newest one decides.
        return $project->featureRequests()
            ->whereNull('parent_id')
            ->where('prompt', 'like', __('Make the first version:').'%')
            ->latest('id')
            ->first();
    }
}
