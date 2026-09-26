<?php

namespace App\Actions\Operations;

use App\Enums\DeploymentStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Verification;
use App\Operations\ChangeOutcome;
use App\Operations\ModelCalls;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every owner's change requests, newest first, for operators. Rows never
 * carry the owner's words or their code: only facts about how the change
 * went.
 */
class ListChanges
{
    /**
     * Get a page of changes matching the filters.
     *
     * @param  array{project?: int, from?: string, to?: string, outcome?: string, verification?: string, driver?: string, provider?: string, model?: string, reason?: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function handle(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $query = FeatureRequest::query()
            ->with(['project:id,name', 'latestRun'])
            ->withExists([
                'runs as completed' => fn (Builder $runs) => $runs->where('status', RunStatus::Completed),
                'deployments as pushed' => fn (Builder $deployments) => $deployments->whereNotNull('pushed_at'),
                'deployments as published' => fn (Builder $deployments) => $deployments->where('status', DeploymentStatus::Published),
            ])
            ->addSelect(['latest_verification' => Verification::query()
                ->select('status')
                ->whereColumn('feature_request_id', 'feature_requests.id')
                ->latest('id')
                ->limit(1),
            ])
            ->latest('id');

        $this->filter($query, $filters);

        $page = $query->paginate($perPage)->withQueryString();
        $calls = $this->calls(array_values($page->getCollection()->map(fn (FeatureRequest $change) => $change->id)->all()));

        // The decision model's calls about a request count toward its cost.
        return $page->through(fn (FeatureRequest $change) => $this->row($change, [...$calls[$change->id] ?? [], ...$change->decision_model_calls ?? []]));
    }

    /**
     * @param  Builder<FeatureRequest>  $query
     * @param  array{project?: int, from?: string, to?: string, outcome?: string, verification?: string, driver?: string, provider?: string, model?: string, reason?: string}  $filters
     */
    protected function filter(Builder $query, array $filters): void
    {
        if (isset($filters['project'])) {
            $query->where('project_id', $filters['project']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay());
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['to'])->endOfDay());
        }

        if (isset($filters['outcome'])) {
            ChangeOutcome::from($filters['outcome'])->scope($query);
        }

        if (isset($filters['verification'])) {
            // The latest check has this status: one has it and none is newer.
            $query->whereHas('verifications', fn (Builder $verifications) => $verifications
                ->where('status', $filters['verification'])
                ->whereNotExists(fn ($newer) => $newer
                    ->from('verifications as newer')
                    ->whereColumn('newer.feature_request_id', 'verifications.feature_request_id')
                    ->whereColumn('newer.id', '>', 'verifications.id')));
        }

        if (isset($filters['driver'])) {
            $query->whereHas('runs', fn (Builder $runs) => $runs->where('driver', $filters['driver']));
        }

        foreach (['provider', 'model'] as $field) {
            if (isset($filters[$field])) {
                $query->whereHas('runs.events', fn (Builder $events) => $events->where('type', 'model_call')->where("data->{$field}", $filters[$field]));
            }
        }

        // Any run of the change stopped or failed for this reason, even if
        // it later carried on.
        if (isset($filters['reason'])) {
            $reason = $filters['reason'];

            $query->whereHas('runs', fn (Builder $runs) => $runs->where(fn (Builder $runs) => $runs
                ->where('stop_reason', $reason)
                ->orWhereHas('events', fn (Builder $events) => $events
                    ->where('type', 'status')
                    ->whereIn('data->to', [RunStatus::Failed->value, RunStatus::NeedsUserDecision->value])
                    ->where('data->reason', $reason))));
        }
    }

    /**
     * The model calls of the given changes, grouped by change.
     *
     * @param  list<int>  $changeIds
     * @return array<int, list<array<string, mixed>>>
     */
    protected function calls(array $changeIds): array
    {
        $calls = [];

        RunEvent::query()
            ->join('runs', 'runs.id', '=', 'run_events.run_id')
            ->whereIn('runs.feature_request_id', $changeIds)
            ->where('run_events.type', 'model_call')
            ->get(['runs.feature_request_id', 'run_events.data'])
            ->each(function (RunEvent $event) use (&$calls) {
                $calls[(int) $event->getAttribute('feature_request_id')][] = $event->data ?? [];
            });

        return $calls;
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array<string, mixed>
     */
    protected function row(FeatureRequest $change, array $calls): array
    {
        $run = $change->latestRun;
        $priced = array_filter($calls, fn (array $call) => ModelCalls::source($call) !== null);
        $end = $run->finished_at ?? ($run !== null && ! $run->status->finished() ? CarbonImmutable::now() : null);

        /** @var Project $project */
        $project = $change->project;

        return [
            'id' => $change->id,
            'project' => ['id' => $project->id, 'name' => $project->name],
            'created_at' => $change->created_at?->toIso8601String(),
            'outcome' => ChangeOutcome::of($change)->value,
            'completed' => (bool) $change->getAttribute('completed'),
            'verification' => $change->getAttribute('latest_verification'),
            'pushed' => (bool) $change->getAttribute('pushed'),
            'published' => (bool) $change->getAttribute('published'),
            'driver' => $run?->driver,
            'models' => array_values(array_unique(array_filter(array_map(fn (array $call) => is_string($call['model'] ?? null) ? $call['model'] : null, $calls)))),
            'calls' => count($calls),
            'unpriced_calls' => count($calls) - count($priced),
            'cost_usd' => round(array_sum(array_map(fn (array $call) => (float) $call['cost_usd'], $priced)), 4),
            'repairs' => $run?->repairs,
            'stop_reason' => $run?->stop_reason,
            'elapsed_seconds' => $end !== null && $change->created_at !== null ? (int) $change->created_at->diffInSeconds($end) : null,
        ];
    }

    /**
     * Get the choices each filter offers, from what has been recorded.
     *
     * @return array{projects: list<array{id: int, name: string}>, drivers: list<string>, providers: list<string>, models: list<string>, reasons: list<string>, outcomes: list<array{value: string, label: string}>}
     */
    public function options(): array
    {
        $calls = RunEvent::query()->where('type', 'model_call');

        return [
            'projects' => array_values(Project::query()->orderBy('name')->limit(500)->get(['id', 'name'])->map(fn (Project $project) => ['id' => $project->id, 'name' => $project->name])->all()),
            'drivers' => $this->strings(Run::query()->distinct()->orderBy('driver')->pluck('driver')->all()),
            'providers' => $this->strings((clone $calls)->toBase()->distinct()->selectRaw("data->>'provider' as value")->orderBy('value')->pluck('value')->all()),
            'models' => $this->strings((clone $calls)->toBase()->distinct()->selectRaw("data->>'model' as value")->orderBy('value')->pluck('value')->all()),
            'reasons' => $this->strings(RunEvent::query()
                ->where('type', 'status')
                ->whereIn('data->to', [RunStatus::Failed->value, RunStatus::NeedsUserDecision->value])
                ->toBase()
                ->distinct()
                ->selectRaw("data->>'reason' as value")
                ->orderBy('value')
                ->pluck('value')
                ->all()),
            'outcomes' => array_map(fn (ChangeOutcome $outcome) => ['value' => $outcome->value, 'label' => $outcome->label()], ChangeOutcome::cases()),
        ];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    protected function strings(array $values): array
    {
        return array_values(array_filter($values, fn (mixed $value) => is_string($value) && $value !== ''));
    }
}
