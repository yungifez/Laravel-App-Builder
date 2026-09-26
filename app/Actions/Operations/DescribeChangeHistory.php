<?php

namespace App\Actions\Operations;

use App\Enums\DeploymentStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Models\Decision;
use App\Models\Deployment;
use App\Models\FeatureRequest;
use App\Models\Preview;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\User;
use App\Models\Verification;
use App\Models\WorkspaceCommand;
use App\Operations\ChangeOutcome;
use App\Operations\ModelCalls;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * One change's history for an operator: request, questions, plan, coding
 * attempts, checks, review, preview, keeping and publishing, with where the
 * time went.
 *
 * Time is split three ways from the run's recorded state changes:
 * - queue: waiting for a worker (queued, and the parts of "verifying" before
 *   the checks started and after they ended);
 * - machine: a worker or the checks were busy (planning, implementing,
 *   reviewing, cancelling, and the checks themselves);
 * - owner: the run waited for the owner's answer or choice.
 * A worker that died mid-state shows as machine time until another worker
 * takes over, since nothing records when it died.
 */
class DescribeChangeHistory
{
    /**
     * @return array<string, mixed>
     */
    public function handle(FeatureRequest $change): array
    {
        $change->loadMissing(['project:id,name', 'user:id,email', 'latestRun']);
        $runs = $change->runs()->orderBy('id')->get();
        $verifications = $change->verifications()->orderBy('id')->get();
        $now = CarbonImmutable::now();

        $described = [];
        $totals = ['queue_seconds' => 0, 'machine_seconds' => 0, 'owner_seconds' => 0];

        foreach ($runs as $index => $run) {
            $entry = $this->run($run, $index + 1, array_values($verifications->where('run_id', $run->id)->all()), $now);
            $described[] = $entry;

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $entry['time'][$key];
            }
        }

        $lastEnd = $runs->last()?->finished_at;

        /** @var User $owner */
        $owner = $change->user;

        return [
            'change' => [
                'id' => $change->id,
                'project' => ['id' => $change->project->id, 'name' => $change->project->name],
                'owner' => $owner->email,
                'request' => Str::limit($change->prompt, 500),
                'target_step' => $change->target_step,
                'generator' => $change->generator,
                'status' => $change->status->value,
                'outcome' => ChangeOutcome::of($change)->value,
                'outcome_label' => ChangeOutcome::of($change)->label(),
                'error' => $change->error === null ? null : Str::limit($change->error, 1000),
                'created_at' => $change->created_at?->toIso8601String(),
                'base_revision' => $change->base_revision,
                'patch' => $change->patch === null ? null : [
                    'sha256' => hash('sha256', $change->patch),
                    'bytes' => strlen($change->patch),
                    'files' => preg_match_all('/^diff --git /m', $change->patch),
                ],
                'commit_sha' => $change->commit_sha,
                'accepted_at' => $change->accepted_at?->toIso8601String(),
                'revert_sha' => $change->revert_sha,
                'reverted_at' => $change->reverted_at?->toIso8601String(),
            ],
            'milestones' => $this->milestones($change, array_values($runs->all()), $verifications->last()),
            'related' => [
                'parent' => $change->parent_id,
                'retry_of' => $change->retry_of_id,
                'retries' => FeatureRequest::query()->where('retry_of_id', $change->id)->orderBy('id')->pluck('id')->all(),
                'follow_ups' => $change->followUps()->whereNull('retry_of_id')->orderBy('id')->pluck('id')->all(),
            ],
            'time' => [
                ...$totals,
                'total_seconds' => $change->created_at === null ? null : (int) $change->created_at->diffInSeconds($lastEnd ?? $now),
                // After the change was built, until the owner kept it.
                'until_kept_seconds' => $lastEnd !== null && $change->accepted_at !== null ? max(0, (int) $lastEnd->diffInSeconds($change->accepted_at)) : null,
            ],
            'decisions' => array_values($change->decisions()->orderBy('id')->get()->map(fn (Decision $decision) => [
                'name' => $decision->name,
                'choice' => $decision->choice,
                'confidence' => round($decision->confidence, 2),
                'acted' => $decision->acted,
                'model' => $decision->model,
                'latency_ms' => $decision->latency_ms,
            ])->all()),
            'runs' => $described,
            // Checks run without a run, such as a rerun the owner asked for.
            'verifications' => array_values(array_map(fn (Verification $verification) => $this->verification($verification), $verifications->whereNull('run_id')->values()->all())),
            'previews' => array_values($change->previews()->orderBy('id')->get()->map(fn (Preview $preview) => [
                'id' => $preview->id,
                'status' => $preview->status->value,
                'created_at' => $preview->created_at?->toIso8601String(),
                'ready_at' => $preview->ready_at?->toIso8601String(),
                'start_seconds' => $preview->ready_at !== null && $preview->created_at !== null ? (int) $preview->created_at->diffInSeconds($preview->ready_at) : null,
                'stopped_at' => $preview->stopped_at?->toIso8601String(),
                'error' => $preview->error === null ? null : Str::limit($preview->error, 1000),
            ])->all()),
            'deployments' => array_values($change->deployments()->orderBy('deployments.id')->get()->map(fn (Deployment $deployment) => [
                'id' => $deployment->id,
                'status' => $deployment->status->value,
                'commit_sha' => $deployment->commit_sha,
                'created_at' => $deployment->created_at?->toIso8601String(),
                'pushed_at' => $deployment->pushed_at?->toIso8601String(),
                'confirmed_at' => $deployment->confirmed_at?->toIso8601String(),
                'finished_at' => $deployment->finished_at?->toIso8601String(),
                'error' => $deployment->error === null ? null : Str::limit($deployment->error, 1000),
            ])->all()),
        ];
    }

    /**
     * The separate things that can be true of a change. Healthy is always
     * null: nothing checks a published app after its first confirmation.
     *
     * @param  list<Run>  $runs
     * @return array{completed: bool, verification: string|null, kept: bool, reverted: bool, pushed: bool, published: bool, healthy: null}
     */
    protected function milestones(FeatureRequest $change, array $runs, ?Verification $latest): array
    {
        return [
            'completed' => collect($runs)->contains(fn (Run $run) => $run->status === RunStatus::Completed),
            'verification' => $latest?->status->value,
            'kept' => $change->commit_sha !== null && $change->reverted_at === null,
            'reverted' => $change->reverted_at !== null,
            'pushed' => $change->deployments()->whereNotNull('pushed_at')->exists(),
            'published' => $change->deployments()->where('status', DeploymentStatus::Published)->exists(),
            'healthy' => null,
        ];
    }

    /**
     * @param  list<Verification>  $verifications
     * @return array<string, mixed>
     */
    protected function run(Run $run, int $attempt, array $verifications, CarbonImmutable $now): array
    {
        $events = $run->events()->get();
        $segments = $this->segments($run, array_values($events->where('type', 'status')->all()), $verifications, $now);
        $time = ['queue_seconds' => 0, 'machine_seconds' => 0, 'owner_seconds' => 0];

        foreach ($segments as $segment) {
            foreach ($segment['split'] as $kind => $seconds) {
                $time["{$kind}_seconds"] += $seconds;
            }
        }

        $operations = [];

        foreach ($events->where('type', 'operation') as $event) {
            $tool = (string) ($event->data['tool'] ?? 'unknown');
            $operations[$tool] ??= ['tool' => $tool, 'count' => 0, 'failed' => 0];
            $operations[$tool]['count']++;
            $operations[$tool]['failed'] += in_array($event->data['status'] ?? null, ['failed', 'rejected'], true) ? 1 : 0;
        }

        return [
            'id' => $run->id,
            'attempt' => $attempt,
            'driver' => $run->driver,
            'config_version' => $run->config_version,
            'status' => $run->status->value,
            'stop_reason' => $run->stop_reason,
            'error' => $run->error === null ? null : Str::limit($run->error, 1000),
            'repairs' => $run->repairs,
            'created_at' => $run->created_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'time' => $time,
            'segments' => array_map(fn (array $segment) => [
                'status' => $segment['status'],
                'started_at' => $segment['start']->toIso8601String(),
                'seconds' => (int) $segment['start']->diffInSeconds($segment['end']),
                'split' => $segment['split'],
            ], $segments),
            'events' => array_values($events->reject(fn (RunEvent $event) => in_array($event->type, ['operation', 'model_call'], true))->map(fn (RunEvent $event) => [
                'sequence' => $event->sequence,
                'type' => $event->type,
                'at' => $event->created_at?->toIso8601String(),
                'summary' => $this->summary($event),
            ])->all()),
            'operations' => array_values($operations),
            'model_calls' => array_values($events->where('type', 'model_call')->map(fn (RunEvent $event) => [
                'role' => $event->data['role'] ?? null,
                'provider' => $event->data['provider'] ?? null,
                'model' => $event->data['model'] ?? null,
                'input_tokens' => (int) ($event->data['input_tokens'] ?? 0),
                'output_tokens' => (int) ($event->data['output_tokens'] ?? 0),
                'cost_usd' => is_numeric($event->data['cost_usd'] ?? null) ? (float) $event->data['cost_usd'] : null,
                'cost_source' => ModelCalls::source($event->data ?? []),
                'status' => $event->data['status'] ?? null,
                'error_kind' => $event->data['error_kind'] ?? null,
                'at' => $event->created_at?->toIso8601String(),
            ])->all()),
            'verifications' => array_map(fn (Verification $verification) => $this->verification($verification), $verifications),
            'failed_commands' => $run->workspace_id === null ? [] : array_values(WorkspaceCommand::query()
                ->where('workspace_id', $run->workspace_id)
                ->where(fn ($query) => $query->where('exit_code', '!=', 0)->orWhere('timed_out', true))
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (WorkspaceCommand $command) => [
                    'command' => Str::limit(implode(' ', $command->command), 200),
                    'exit_code' => $command->exit_code,
                    'timed_out' => $command->timed_out,
                    'duration_ms' => $command->duration_ms,
                    'at' => $command->created_at?->toIso8601String(),
                    'output' => Str::limit(trim(mb_substr($command->error_output ?: $command->output, -1000)), 1000),
                ])->all()),
        ];
    }

    /**
     * Cut the run's life into the states it was in, and split each state's
     * time into queue, machine and owner time.
     *
     * @param  list<RunEvent>  $changes
     * @param  list<Verification>  $verifications
     * @return list<array{status: string, start: CarbonImmutable, end: CarbonImmutable, split: array<string, int>}>
     */
    public function segments(Run $run, array $changes, array $verifications, CarbonImmutable $now): array
    {
        $segments = [];
        $status = RunStatus::Queued->value;
        $start = CarbonImmutable::parse($run->created_at ?? $now);

        foreach ($changes as $change) {
            $at = CarbonImmutable::parse($change->created_at ?? $now);
            $segments[] = ['status' => $status, 'start' => $start, 'end' => $at];
            $status = (string) ($change->data['to'] ?? $status);
            $start = $at;
        }

        if (! RunStatus::from($status)->finished()) {
            $segments[] = ['status' => $status, 'start' => $start, 'end' => CarbonImmutable::parse($run->finished_at ?? $now)];
        }

        return array_map(fn (array $segment) => [...$segment, 'split' => $this->split($segment, $verifications)], $segments);
    }

    /**
     * @param  array{status: string, start: CarbonImmutable, end: CarbonImmutable}  $segment
     * @param  list<Verification>  $verifications
     * @return array<string, int>
     */
    protected function split(array $segment, array $verifications): array
    {
        $seconds = max(0, (int) $segment['start']->diffInSeconds($segment['end']));

        return match ($segment['status']) {
            RunStatus::Queued->value => ['queue' => $seconds],
            RunStatus::NeedsUserDecision->value => ['owner' => $seconds],
            RunStatus::Verifying->value => $this->splitVerifying($segment, $verifications, $seconds),
            default => ['machine' => $seconds],
        };
    }

    /**
     * Checks running inside the verifying state are machine time; the rest
     * of the state is waiting for a worker to start or pick up after them.
     *
     * @param  array{status: string, start: CarbonImmutable, end: CarbonImmutable}  $segment
     * @param  list<Verification>  $verifications
     * @return array<string, int>
     */
    protected function splitVerifying(array $segment, array $verifications, int $seconds): array
    {
        $machine = 0;

        foreach ($verifications as $verification) {
            if ($verification->started_at === null) {
                continue;
            }

            $from = max($segment['start']->getTimestamp(), $verification->started_at->getTimestamp());
            $to = min($segment['end']->getTimestamp(), ($verification->finished_at ?? $segment['end'])->getTimestamp());
            $machine += max(0, $to - $from);
        }

        $machine = min($machine, $seconds);

        return ['queue' => $seconds - $machine, 'machine' => $machine];
    }

    /**
     * @return array<string, mixed>
     */
    protected function verification(Verification $verification): array
    {
        return [
            'id' => $verification->id,
            'status' => $verification->status->value,
            'meaning' => match ($verification->status) {
                VerificationStatus::Passed => 'Every check passed, including tests for this change.',
                VerificationStatus::Unverified => 'Every check passed, but no test covers this change, so it is not proven to work.',
                VerificationStatus::Failed => 'At least one check failed.',
                VerificationStatus::Errored => 'The checks could not run to the end.',
                default => 'Not finished.',
            },
            'created_at' => $verification->created_at?->toIso8601String(),
            'started_at' => $verification->started_at?->toIso8601String(),
            'finished_at' => $verification->finished_at?->toIso8601String(),
            'wait_seconds' => $verification->started_at !== null && $verification->created_at !== null ? (int) $verification->created_at->diffInSeconds($verification->started_at) : null,
            'seconds' => $verification->started_at !== null && $verification->finished_at !== null ? (int) $verification->started_at->diffInSeconds($verification->finished_at) : null,
            'error' => $verification->error === null ? null : Str::limit($verification->error, 1000),
            'results' => array_map(fn (array $result) => [
                'name' => $result['name'],
                'stage' => $result['stage'],
                'outcome' => $result['outcome'],
                'exit_code' => $result['exit_code'],
                'timed_out' => $result['timed_out'],
                'duration_ms' => $result['duration_ms'],
                // Output only where it explains a problem, and bounded.
                'output' => $result['outcome'] === 'passed' ? null : Str::limit(trim(mb_substr($result['output'], -1000)), 1000),
            ], $verification->results ?? []),
        ];
    }

    /**
     * Describe an event in a line, without the model's or owner's full text.
     */
    protected function summary(RunEvent $event): string
    {
        $data = $event->data ?? [];

        return match ($event->type) {
            'created' => 'Queued with the '.($data['driver'] ?? '?').' driver',
            'lease_acquired' => ($data['took_over'] ?? false) ? 'A worker took the run over' : 'A worker picked the run up',
            'status' => ($data['from'] ?? '?').' → '.($data['to'] ?? '?').(isset($data['reason']) ? " ({$data['reason']})" : '').(isset($data['question']) ? ': '.Str::limit((string) $data['question'], 200) : ''),
            'workspace_ready' => 'Workspace ready',
            'context_compiled' => 'Context compiled',
            'build_finished' => 'Coding attempt '.((int) ($data['attempt'] ?? 0) + 1).' finished',
            'formatted' => 'Formatted',
            'failover' => 'Switched from '.($data['from'] ?? '?').' to '.($data['to'] ?? '?').' ('.($data['reason'] ?? '?').')',
            'review' => (($data['approved'] ?? false) ? 'Review approved' : 'Review found problems').': '.count((array) ($data['findings'] ?? [])).' findings',
            'plan_rejected' => 'Plan rejected: '.Str::limit((string) ($data['error'] ?? ''), 200),
            'change_accepted' => 'Kept as '.Str::limit((string) ($data['commit'] ?? ''), 12, ''),
            'change_reverted' => 'Undone by '.Str::limit((string) ($data['revert'] ?? ''), 12, ''),
            default => Str::headline($event->type),
        };
    }
}
