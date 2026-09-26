<?php

namespace App\Operations;

use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where one change request ended up, for operators. Every request has
 * exactly one outcome, checked in this order:
 *
 * - reverted: it was kept, then undone.
 * - kept: the owner kept it (it has a commit) and it is not undone.
 * - answered: the owner only asked a question; nothing was built.
 * - cancelled: the owner stopped it.
 * - failed: its latest run failed, or it could not be generated.
 * - waiting_on_owner: its latest run waits for the owner's answer or choice.
 * - in_progress: its latest run is still working.
 * - built: its latest run completed, but the owner has not kept it.
 *
 * "Built" says nothing about checks. Whether the checks passed is separate
 * (see Milestones): a change can be built with its checks unverified.
 */
enum ChangeOutcome: string
{
    case Reverted = 'reverted';
    case Kept = 'kept';
    case Answered = 'answered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case WaitingOnOwner = 'waiting_on_owner';
    case InProgress = 'in_progress';
    case Built = 'built';

    /**
     * Run states in which a run is still working.
     *
     * @var list<RunStatus>
     */
    public const WORKING = [RunStatus::Queued, RunStatus::Planning, RunStatus::Implementing, RunStatus::Verifying, RunStatus::Reviewing];

    /**
     * Get the outcome of a request whose latest run is loaded.
     */
    public static function of(FeatureRequest $request): self
    {
        $run = $request->latestRun?->status;

        return match (true) {
            $request->reverted_at !== null => self::Reverted,
            $request->commit_sha !== null => self::Kept,
            $request->status === FeatureRequestStatus::Answered => self::Answered,
            $request->status === FeatureRequestStatus::Cancelled, in_array($run, [RunStatus::Cancelled, RunStatus::Cancelling], true) => self::Cancelled,
            $request->status === FeatureRequestStatus::Failed, $run === RunStatus::Failed => self::Failed,
            $run === RunStatus::NeedsUserDecision => self::WaitingOnOwner,
            in_array($run, self::WORKING, true), $run === null && $request->status === FeatureRequestStatus::Generating => self::InProgress,
            default => self::Built,
        };
    }

    /**
     * Limit a query of requests to those with this outcome, matching of().
     *
     * @param  Builder<FeatureRequest>  $query
     */
    public function scope(Builder $query): void
    {
        $closedStatuses = [FeatureRequestStatus::Answered, FeatureRequestStatus::Cancelled, FeatureRequestStatus::Failed];
        $latestIn = fn (array $statuses) => fn (Builder $query) => $query->whereHas('latestRun', fn (Builder $run) => $run->whereIn('status', $statuses));

        if ($this === self::Reverted) {
            $query->whereNotNull('reverted_at');

            return;
        }

        if ($this === self::Kept) {
            $query->whereNotNull('commit_sha')->whereNull('reverted_at');

            return;
        }

        $query->whereNull('commit_sha')->whereNull('reverted_at');

        match ($this) {
            self::Answered => $query->where('status', FeatureRequestStatus::Answered),
            self::Cancelled => $query->where('status', '!=', FeatureRequestStatus::Answered)
                ->where(fn (Builder $query) => $query
                    ->where('status', FeatureRequestStatus::Cancelled)
                    ->orWhere($latestIn([RunStatus::Cancelled, RunStatus::Cancelling]))),
            self::Failed => $query->whereNotIn('status', [FeatureRequestStatus::Answered, FeatureRequestStatus::Cancelled])
                ->whereDoesntHave('latestRun', fn (Builder $run) => $run->whereIn('status', [RunStatus::Cancelled, RunStatus::Cancelling]))
                ->where(fn (Builder $query) => $query
                    ->where('status', FeatureRequestStatus::Failed)
                    ->orWhere($latestIn([RunStatus::Failed]))),
            self::WaitingOnOwner => $query->whereNotIn('status', $closedStatuses)
                ->where($latestIn([RunStatus::NeedsUserDecision])),
            self::InProgress => $query->whereNotIn('status', $closedStatuses)
                ->where(fn (Builder $query) => $query
                    ->where($latestIn(self::WORKING))
                    ->orWhere(fn (Builder $query) => $query->whereDoesntHave('runs')->where('status', FeatureRequestStatus::Generating))),
            self::Built => $query->whereNotIn('status', $closedStatuses)
                ->where(fn (Builder $query) => $query
                    ->where($latestIn([RunStatus::Completed]))
                    ->orWhere(fn (Builder $query) => $query->whereDoesntHave('runs')->where('status', '!=', FeatureRequestStatus::Generating))),
        };
    }

    /**
     * Get the words an operator sees.
     */
    public function label(): string
    {
        return match ($this) {
            self::Reverted => 'Undone',
            self::Kept => 'Kept',
            self::Answered => 'Answered',
            self::Cancelled => 'Stopped by owner',
            self::Failed => 'Failed',
            self::WaitingOnOwner => 'Waiting on owner',
            self::InProgress => 'In progress',
            self::Built => 'Built, not kept',
        };
    }
}
