<?php

namespace App\Runs\Drivers;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Models\RunEvent;
use App\Runs\Agents\RunnerAgent;
use App\Runs\Exceptions\ConstructionFailed;
use App\Runs\Exceptions\WaitingForWorker;
use App\Runs\Plan;
use App\Runs\ToolSession;
use App\Workspaces\WorkspaceManager;
use Illuminate\Support\Str;

/**
 * Plans and reviews like the SDK driver, but the change is written by a
 * worker outside our boxes, such as the owner's own Claude Code or Codex.
 * The worker reads the brief and hands the change back as a patch through
 * its tools (routes/ai.php); the patch is applied in our own workspace and
 * then checked and reviewed like any other change (architecture §11).
 */
class WorkerDriver extends SdkDriver
{
    /**
     * Apply the patch the worker handed back since the change last went
     * back to building. Until there is one, the run waits.
     *
     * @throws WaitingForWorker
     * @throws ConstructionFailed
     */
    public function build(Run $run, Plan $plan, ToolSession $tools): string
    {
        $workspace = $run->workspace ?? throw new ConstructionFailed(__('The run has no workspace.'));
        $submission = $this->submission($run) ?? throw new WaitingForWorker;
        $file = RunnerAgent::TASK_DIRECTORY.'/change.patch';

        // Each hand-in is the whole change, so a repair starts from the
        // baseline again. Ignored files (setup output, saved files) stay.
        $baseline = $this->extractCandidateChange->baseline($workspace);
        $this->runWorkspaceCommand->handle($workspace, ['git', 'reset', '-q', '--hard', $baseline], 60);
        $this->runWorkspaceCommand->handle($workspace, ['git', 'clean', '-fdq'], 60);

        app(WorkspaceManager::class)->driver($workspace->driver)->writeFile((string) $workspace->driver_id, $file, (string) $submission->data['patch']);
        $result = $this->runWorkspaceCommand->handle($workspace, ['git', 'apply', '--3way', '--whitespace=nowarn', $file], 120);
        $this->runWorkspaceCommand->handle($workspace, ['rm', '-f', $file], 30);

        if ($result->exit_code !== 0 || $result->timed_out) {
            // The worker hears why through check_status, and hands it back again.
            $run->recordEvent('worker_patch_refused', [
                'submission' => $submission->sequence,
                'reason' => Str::limit(str_replace($file, 'the patch', trim($result->error_output ?: $result->output)), 1000),
            ]);

            throw new WaitingForWorker;
        }

        $this->restoreProtectedPaths($run);

        return (string) $submission->data['summary'];
    }

    /**
     * Get the patch the worker handed back since the change last went back
     * to building, unless it could not be applied.
     */
    public function submission(Run $run): ?RunEvent
    {
        $latest = $this->latestSubmission($run);

        return $latest !== null && $this->refusal($run, $latest) === null ? $latest : null;
    }

    /**
     * Get the latest patch the worker handed back since the change last
     * went back to building.
     */
    public function latestSubmission(Run $run): ?RunEvent
    {
        $since = (int) $run->events()->where('type', 'status')->where('data->to', RunStatus::Implementing->value)->max('sequence');

        /** @var RunEvent|null */
        return $run->events()->where('type', 'worker_submitted')->where('sequence', '>', $since)->reorder('sequence', 'desc')->first();
    }

    /**
     * Get why a patch the worker handed back could not be applied, if it could not.
     */
    public function refusal(Run $run, RunEvent $submission): ?string
    {
        $refused = $run->events()->where('type', 'worker_patch_refused')->where('data->submission', $submission->sequence)->first();

        return $refused === null ? null : (string) $refused->data['reason'];
    }
}
