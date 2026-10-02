<?php

namespace App\Actions\Operations;

use App\Actions\Runners\ScaleRunnerPool;
use App\Enums\BoxCommandStatus;
use App\Enums\DeploymentStatus;
use App\Enums\PreviewStatus;
use App\Enums\RunStatus;
use App\Enums\VerificationStatus;
use App\Enums\WorkspaceStatus;
use App\Models\BoxCommand;
use App\Models\Deployment;
use App\Models\Preview;
use App\Models\PreviewRebuild;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Runner;
use App\Models\Verification;
use App\Models\VisualEdit;
use App\Models\WorkerHeartbeat;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * What an operator should look at now, platform wide. Every count comes
 * from a recorded fact and carries the records behind it.
 *
 * @phpstan-type AttentionRecord array{label: string, detail: string|null, at: string|null, href: string|null}
 * @phpstan-type AttentionItem array{key: string, title: string, count: int, href: string|null, records: list<AttentionRecord>}
 */
class FindAttentionItems
{
    public function __construct(
        private SummarizeSpend $summarizeSpend,
        private SummarizeHostingSpend $summarizeHostingSpend,
    ) {}

    /**
     * Collect everything that needs attention, with failures counted over the
     * last "days" days.
     *
     * @return array{since: string, days: int, workers: list<array{queue: string, backlog: int|null, oldest_wait_seconds: int|null, alive: int, heard_from: bool, attention: bool, workers: list<array{worker: string, queues: string, last_seen_at: string, job: string|null, job_started_at: string|null, alive: bool}>}>, items: list<AttentionItem>, failures: list<array{stage: string, reason: string, count: int, href: string|null}>, rebuilds: array{edits: int, measured: int, median_seconds: float|null, p90_seconds: float|null, max_seconds: float|null, slow: int, not_seen: int}, waiting_on_owner: int, spend: array<string, mixed>, hosting: list<array{host: string, currency: string|null, total_cents: int|null, apps: list<array{project_id: string|null, name: string, cents: int}>, error: bool}>}
     */
    public function handle(int $days): array
    {
        $now = CarbonImmutable::now();
        $since = $now->subDays($days);

        return [
            'since' => $since->toIso8601String(),
            'days' => $days,
            'workers' => $this->workers($now),
            'items' => array_values(array_filter([
                $this->stuckRuns($now),
                $this->expiredLeases($now),
                $this->exhaustedBudgets($since),
                $this->failedPreviewStarts($since),
                $this->failedRebuilds($since),
                $this->stuckRebuilds($now),
                $this->failedCleanups(),
                $this->overdueCleanups($now),
                $this->stuckProvisioning($now),
                $this->orphanedWorkspaces($now),
                $this->lostBoxCommands($since),
                $this->quietRunners($now),
                $this->fullRunners(),
                $this->pausedMachineStarts($now),
                $this->liveErrors($since),
            ], fn (array $item) => $item['count'] > 0)),
            'failures' => $this->failures($since),
            'rebuilds' => $this->rebuildLatency($since),
            'waiting_on_owner' => Run::query()->where('status', RunStatus::NeedsUserDecision)->count(),
            'spend' => $this->summarizeSpend->handle($since),
            'hosting' => $this->summarizeHostingSpend->handle(),
        ];
    }

    /**
     * Each watched queue's backlog, its oldest waiting job and the workers
     * that serve it. A queue nobody has a heartbeat for is not called
     * healthy: it may have no worker at all.
     *
     * @return list<array{queue: string, backlog: int|null, oldest_wait_seconds: int|null, alive: int, heard_from: bool, attention: bool, workers: list<array{worker: string, queues: string, last_seen_at: string, job: string|null, job_started_at: string|null, alive: bool}>}>
     */
    protected function workers(CarbonImmutable $now): array
    {
        $heartbeats = WorkerHeartbeat::query()->orderBy('worker')->get();
        $queues = [];

        foreach ((array) config('operations.workers.queues') as $queue) {
            $serving = $heartbeats->filter(fn (WorkerHeartbeat $heartbeat) => in_array($queue, $heartbeat->queueNames(), true))->values();
            $alive = $serving->filter(fn (WorkerHeartbeat $heartbeat) => $heartbeat->alive($now))->count();

            // The queue store can be down too; then its numbers are unknown.
            $backlog = rescue(fn () => Queue::connection()->pendingSize($queue), null, report: false);
            $oldest = rescue(fn () => Queue::connection()->creationTimeOfOldestPendingJob($queue), null, report: false);
            $wait = is_numeric($oldest) ? max(0, $now->getTimestamp() - (int) $oldest) : null;

            $queues[] = [
                'queue' => (string) $queue,
                'backlog' => is_numeric($backlog) ? (int) $backlog : null,
                'oldest_wait_seconds' => $wait,
                'alive' => $alive,
                'heard_from' => $serving->isNotEmpty(),
                'attention' => $alive === 0 || ($wait !== null && $wait > (int) config('operations.workers.wait_seconds')),
                'workers' => array_values($serving->map(fn (WorkerHeartbeat $heartbeat) => [
                    'worker' => $heartbeat->worker,
                    'queues' => $heartbeat->queues,
                    'last_seen_at' => $heartbeat->last_seen_at->toIso8601String(),
                    'job' => $heartbeat->job,
                    'job_started_at' => $heartbeat->job_started_at?->toIso8601String(),
                    'alive' => $heartbeat->alive($now),
                ])->all()),
            ];
        }

        return $queues;
    }

    /**
     * Runs that are not waiting on their owner and have logged nothing for
     * "stuck_minutes".
     *
     * @return AttentionItem
     */
    protected function stuckRuns(CarbonImmutable $now): array
    {
        $quietSince = $now->subMinutes((int) config('operations.attention.stuck_minutes'));
        $query = Run::query()
            ->whereNotIn('status', [RunStatus::Completed, RunStatus::Cancelled, RunStatus::Failed, RunStatus::NeedsUserDecision])
            ->whereDoesntHave('events', fn (Builder $events) => $events->where('created_at', '>=', $quietSince))
            ->where('created_at', '<', $quietSince);

        return $this->item('stuck_runs', 'Runs with no progress', $query, fn (Run $run) => $this->runRecord($run, 'In '.$run->status->value));
    }

    /**
     * Runs a worker should hold but none does: the lease ran out (or was let
     * go) while the run was in a worker's hands, and runs:reconcile has not
     * picked it up within the grace period.
     *
     * @return AttentionItem
     */
    protected function expiredLeases(CarbonImmutable $now): array
    {
        $cutoff = $now->subSeconds((int) config('operations.attention.lease_grace_seconds'));
        $query = Run::query()
            ->whereIn('status', [RunStatus::Planning, RunStatus::Implementing, RunStatus::Reviewing])
            ->where(fn (Builder $query) => $query
                ->where('lease_expires_at', '<', $cutoff)
                ->orWhere(fn (Builder $query) => $query->whereNull('lease_expires_at')->where('updated_at', '<', $cutoff)));

        return $this->item('expired_leases', 'Runs no worker holds', $query, fn (Run $run) => $this->runRecord(
            $run,
            $run->lease_expires_at === null ? 'No lease' : 'Lease ran out '.$run->lease_expires_at->diffForHumans($now, short: true),
        ));
    }

    /**
     * Runs that stopped because they used up their turns, operations or time.
     *
     * @return AttentionItem
     */
    protected function exhaustedBudgets(CarbonImmutable $since): array
    {
        $query = Run::query()->whereHas('events', fn (Builder $events) => $events
            ->where('type', 'status')
            ->where('data->reason', 'budget_exhausted')
            ->where('created_at', '>=', $since));

        return $this->item('budgets_exhausted', 'Runs out of budget', $query, fn (Run $run) => $this->runRecord($run, Str::limit((string) $run->error, 160)), href: route('operations.changes.index', ['reason' => 'budget_exhausted']));
    }

    /**
     * @return AttentionItem
     */
    protected function failedPreviewStarts(CarbonImmutable $since): array
    {
        $query = Preview::query()->where('status', PreviewStatus::Failed)->where('updated_at', '>=', $since);

        return $this->item('preview_start_failed', 'Previews that did not start', $query, fn (Preview $preview) => [
            'label' => "Preview {$preview->id}",
            'detail' => Str::limit((string) $preview->error, 160),
            'at' => $preview->updated_at?->toIso8601String(),
            'href' => $preview->feature_request_id === null ? null : route('operations.changes.show', $preview->feature_request_id),
        ]);
    }

    /**
     * @return AttentionItem
     */
    protected function failedRebuilds(CarbonImmutable $since): array
    {
        $query = PreviewRebuild::query()->where('status', 'failed')->where('started_at', '>=', $since);

        return $this->item('preview_rebuild_failed', 'Preview rebuilds that failed', $query, fn (PreviewRebuild $rebuild) => [
            'label' => "Preview {$rebuild->preview_id}, project {$rebuild->project_id}",
            'detail' => Str::limit((string) $rebuild->error, 160),
            'at' => $rebuild->started_at->toIso8601String(),
            'href' => null,
        ]);
    }

    /**
     * Rebuilds still marked running after the longest a rebuild may take:
     * the worker died mid-build.
     *
     * @return AttentionItem
     */
    protected function stuckRebuilds(CarbonImmutable $now): array
    {
        $query = PreviewRebuild::query()->where('status', 'running')->where('started_at', '<', $now->subMinutes(20));

        return $this->item('rebuild_stuck', 'Preview rebuilds that never ended', $query, fn (PreviewRebuild $rebuild) => [
            'label' => "Preview {$rebuild->preview_id}, project {$rebuild->project_id}",
            'detail' => null,
            'at' => $rebuild->started_at->toIso8601String(),
            'href' => null,
        ]);
    }

    /**
     * Workspaces whose environment could not be removed and still exist.
     *
     * @return AttentionItem
     */
    protected function failedCleanups(): array
    {
        $query = Workspace::query()->whereNotNull('cleanup_failed_at')->where('status', '!=', WorkspaceStatus::Destroyed);

        return $this->item('workspace_cleanup_failed', 'Workspaces that could not be removed', $query, fn (Workspace $workspace) => $this->workspaceRecord($workspace, Str::limit((string) $workspace->cleanup_error, 160)));
    }

    /**
     * Ready workspaces the reaper should have removed a while ago: it is not
     * running, or it keeps failing.
     *
     * @return AttentionItem
     */
    protected function overdueCleanups(CarbonImmutable $now): array
    {
        $grace = (int) config('operations.attention.cleanup_grace_minutes');
        $query = Workspace::query()->reapable(
            $now->subMinutes((int) config('workspaces.lifetime.idle_minutes') + $grace),
            $now->subMinutes($grace),
        );

        return $this->item('workspace_cleanup_overdue', 'Workspaces past their cleanup time', $query, fn (Workspace $workspace) => $this->workspaceRecord($workspace, 'Last used '.($workspace->last_activity_at?->diffForHumans($now, short: true) ?? 'never')));
    }

    /**
     * @return AttentionItem
     */
    protected function stuckProvisioning(CarbonImmutable $now): array
    {
        $query = Workspace::query()->where('status', WorkspaceStatus::Provisioning)->where('created_at', '<', $now->subMinutes(15));

        return $this->item('workspace_provisioning_stuck', 'Workspaces stuck starting', $query, fn (Workspace $workspace) => $this->workspaceRecord($workspace, null));
    }

    /**
     * Ready workspaces nothing uses: no unfinished run, no live preview and
     * no running verification, and untouched for the grace period.
     *
     * @return AttentionItem
     */
    protected function orphanedWorkspaces(CarbonImmutable $now): array
    {
        $idle = $now->subMinutes((int) config('operations.attention.cleanup_grace_minutes'));
        $query = Workspace::query()
            ->where('status', WorkspaceStatus::Ready)
            ->where(fn (Builder $query) => $query->where('last_activity_at', '<', $idle)->orWhere(fn (Builder $query) => $query->whereNull('last_activity_at')->where('created_at', '<', $idle)))
            ->whereNotExists(fn ($query) => $query->from('runs')->whereColumn('runs.workspace_id', 'workspaces.id')->whereNotIn('runs.status', [RunStatus::Completed->value, RunStatus::Cancelled->value, RunStatus::Failed->value]))
            ->whereNotExists(fn ($query) => $query->from('previews')->whereColumn('previews.workspace_id', 'workspaces.id')->whereIn('previews.status', [PreviewStatus::Starting->value, PreviewStatus::Ready->value]))
            ->whereNotExists(fn ($query) => $query->from('verifications')->whereColumn('verifications.workspace_id', 'workspaces.id')->whereIn('verifications.status', [VerificationStatus::Queued->value, VerificationStatus::Running->value]));

        return $this->item('workspace_orphaned', 'Workspaces nothing uses', $query, fn (Workspace $workspace) => $this->workspaceRecord($workspace, 'Last used '.($workspace->last_activity_at?->diffForHumans($now, short: true) ?? 'never')));
    }

    /**
     * Box commands no runner took or finished in time: a box stopped
     * answering.
     *
     * @return AttentionItem
     */
    protected function lostBoxCommands(CarbonImmutable $since): array
    {
        $query = BoxCommand::query()->where('status', BoxCommandStatus::Lost)->where('created_at', '>=', $since);

        return $this->item('box_commands_lost', 'Box commands with no answer', $query, fn (BoxCommand $command) => [
            'label' => "{$command->type} in {$command->box}",
            'detail' => $command->claimed_at === null ? 'Never taken' : 'Taken, never finished',
            'at' => $command->created_at?->toIso8601String(),
            'href' => null,
        ]);
    }

    /**
     * Published apps whose online version raised errors, newest first.
     *
     * @return AttentionItem
     */
    protected function liveErrors(CarbonImmutable $since): array
    {
        $query = Deployment::query()
            ->where('status', DeploymentStatus::Published)
            ->where('live_errors_checked_at', '>=', $since)
            ->whereJsonLength('live_errors', '>', 0);

        return $this->item('published_errors', 'Published apps raising errors', $query, function (Deployment $deployment) {
            $top = $deployment->live_errors[0] ?? null;

            return [
                'label' => "Project {$deployment->project_id}, deployment {$deployment->id}: {$deployment->liveErrorCount()} error(s)",
                'detail' => $top === null ? null : Str::limit(trim(($top['class'] ?? '').' '.$top['message']).' (×'.$top['count'].')', 160),
                'at' => $top['last_at'] ?? null,
                'href' => null,
            ];
        });
    }

    /**
     * Runner machines that stopped asking for work, so no new workspace
     * goes to them and the ones on them may be stuck. Draining runners and
     * machines still starting are left out.
     *
     * @return AttentionItem
     */
    protected function quietRunners(CarbonImmutable $now): array
    {
        $query = Runner::query()
            ->whereNull('draining_at')
            ->where('last_seen_at', '<', $now->subSeconds((int) config('workspaces.boxes.pool.online_seconds')));

        return $this->item('runners_quiet', 'Runner machines not answering', $query, fn (Runner $runner) => [
            'label' => "Runner {$runner->name}",
            'detail' => 'Last asked for work '.$runner->last_seen_at?->diffForHumans($now, short: true).($runner->cloud === null ? '. Start its runner again, or remove it with runners:remove --gone.' : '. The pool deletes the machine if it stays quiet.'),
            'at' => $runner->last_seen_at?->toIso8601String(),
            'href' => null,
        ]);
    }

    /**
     * Runner machines too full to take new workspaces.
     *
     * @return AttentionItem
     */
    protected function fullRunners(): array
    {
        $query = Runner::query()->whereNull('draining_at')->where('disk_free_mb', '<', (int) config('workspaces.boxes.pool.min_free_disk_mb'));

        return $this->item('runners_full', 'Runner machines with a nearly full disk', $query, fn (Runner $runner) => [
            'label' => "Runner {$runner->name}",
            'detail' => "{$runner->disk_free_mb} MB free. New workspaces go to other machines.",
            'at' => $runner->last_seen_at?->toIso8601String(),
            'href' => null,
        ]);
    }

    /**
     * The cloud pool stopped starting machines because the last new one
     * never answered.
     *
     * @return AttentionItem
     */
    protected function pausedMachineStarts(CarbonImmutable $now): array
    {
        // Redis gives a stored number back as a string.
        $until = (int) Cache::get(ScaleRunnerPool::PAUSED_UNTIL, 0);
        $paused = $until > $now->getTimestamp();

        return [
            'key' => 'machine_starts_paused',
            'title' => 'Cloud machines not starting',
            'count' => $paused ? 1 : 0,
            'href' => null,
            'records' => $paused ? [[
                'label' => 'No machine starts until '.CarbonImmutable::createFromTimestamp($until)->format('H:i'),
                'detail' => Str::limit((string) Cache::get(ScaleRunnerPool::PAUSED_BECAUSE, ''), 240) ?: null,
                'at' => null,
                'href' => null,
            ]] : [],
        ];
    }

    /**
     * Failures and stops in the window, grouped by the stage they happened
     * in and the recorded reason. Questions to the owner are not failures.
     *
     * @return list<array{stage: string, reason: string, count: int, href: string|null}>
     */
    protected function failures(CarbonImmutable $since): array
    {
        $groups = [];

        RunEvent::query()
            ->where('type', 'status')
            ->where('created_at', '>=', $since)
            ->whereIn('data->to', [RunStatus::Failed->value, RunStatus::NeedsUserDecision->value])
            ->where(fn (Builder $query) => $query->whereNull('data->reason')->orWhere('data->reason', '!=', 'question'))
            ->toBase()
            ->selectRaw("data->>'from' as stage, coalesce(data->>'reason', 'unknown') as reason, count(*) as count")
            ->groupByRaw("data->>'from', coalesce(data->>'reason', 'unknown')")
            ->get()
            ->each(function (object $row) use (&$groups) {
                /** @var object{stage: string|null, reason: string, count: int|string} $row */
                $groups[] = ['stage' => (string) $row->stage, 'reason' => $row->reason, 'count' => (int) $row->count, 'href' => route('operations.changes.index', ['reason' => $row->reason])];
            });

        foreach ([VerificationStatus::Failed, VerificationStatus::Errored] as $status) {
            $count = Verification::query()->where('status', $status)->where('finished_at', '>=', $since)->count();

            if ($count > 0) {
                $groups[] = ['stage' => 'verification', 'reason' => "checks_{$status->value}", 'count' => $count, 'href' => route('operations.changes.index', ['verification' => $status->value])];
            }
        }

        $counts = [
            ['preview', 'start_failed', Preview::query()->where('status', PreviewStatus::Failed)->where('updated_at', '>=', $since)->count()],
            ['preview', 'rebuild_failed', PreviewRebuild::query()->where('status', 'failed')->where('started_at', '>=', $since)->count()],
            ['publish', 'failed', Deployment::query()->where('status', DeploymentStatus::Failed)->where('created_at', '>=', $since)->count()],
            ['publish', 'not_answering', Deployment::query()->where('status', DeploymentStatus::NeedsAttention)->where('created_at', '>=', $since)->count()],
        ];

        foreach ($counts as [$stage, $reason, $count]) {
            if ($count > 0) {
                $groups[] = ['stage' => $stage, 'reason' => $reason, 'count' => $count, 'href' => null];
            }
        }

        usort($groups, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $groups;
    }

    /**
     * How long the owner waited to see a visual edit: from saving it to the
     * end of the first successful rebuild that started after it (a rebuild
     * takes in every commit made before it started). Edits made with no
     * preview to rebuild are counted as not seen.
     *
     * @return array{edits: int, measured: int, median_seconds: float|null, p90_seconds: float|null, max_seconds: float|null, slow: int, not_seen: int}
     */
    protected function rebuildLatency(CarbonImmutable $since): array
    {
        $edits = VisualEdit::query()->where('created_at', '>=', $since)->latest('id')->limit(500)->get(['id', 'project_id', 'created_at']);
        $rebuilds = PreviewRebuild::query()
            ->whereIn('project_id', $edits->pluck('project_id')->unique())
            ->where('status', 'rebuilt')
            ->where('started_at', '>=', $since)
            ->orderBy('started_at')
            ->get(['project_id', 'started_at', 'finished_at'])
            ->groupBy('project_id');

        $seconds = [];

        foreach ($edits as $edit) {
            $shown = $rebuilds->get($edit->project_id)?->first(fn (PreviewRebuild $rebuild) => $rebuild->started_at->gte($edit->created_at) && $rebuild->finished_at !== null);

            if ($shown !== null) {
                $seconds[] = round($edit->created_at->diffInMilliseconds($shown->finished_at) / 1000, 1);
            }
        }

        sort($seconds);
        $count = count($seconds);

        return [
            'edits' => $edits->count(),
            'measured' => $count,
            'median_seconds' => $count > 0 ? $seconds[intdiv($count, 2)] : null,
            'p90_seconds' => $count > 0 ? $seconds[min($count - 1, (int) floor($count * 0.9))] : null,
            'max_seconds' => $count > 0 ? $seconds[$count - 1] : null,
            'slow' => count(array_filter($seconds, fn (float $value) => $value > (int) config('operations.attention.slow_rebuild_seconds'))),
            'not_seen' => $edits->count() - $count,
        ];
    }

    /**
     * Count a query and take its first records.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): AttentionRecord  $record
     * @return AttentionItem
     */
    protected function item(string $key, string $title, Builder $query, callable $record, ?string $href = null): array
    {
        $count = (clone $query)->count();

        return [
            'key' => $key,
            'title' => $title,
            'count' => $count,
            'href' => $href,
            'records' => $count === 0 ? [] : array_values($query->latest()->limit((int) config('operations.attention.records'))->get()->map($record)->all()),
        ];
    }

    /**
     * @return AttentionRecord
     */
    protected function runRecord(Run $run, ?string $detail): array
    {
        return [
            'label' => "Run {$run->id}",
            'detail' => $detail,
            'at' => $run->updated_at?->toIso8601String(),
            'href' => route('operations.changes.show', $run->feature_request_id),
        ];
    }

    /**
     * @return AttentionRecord
     */
    protected function workspaceRecord(Workspace $workspace, ?string $detail): array
    {
        return [
            'label' => "Workspace {$workspace->id} ({$workspace->driver})",
            'detail' => $detail,
            'at' => $workspace->created_at?->toIso8601String(),
            'href' => null,
        ];
    }
}
