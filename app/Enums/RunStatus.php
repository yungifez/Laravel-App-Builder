<?php

namespace App\Enums;

/**
 * The states of a construction run.
 *
 * queued → planning → implementing → verifying → reviewing → completed, with
 * transitions to needs_user_decision, cancelling → cancelled, or failed. A
 * repair returns to implementing.
 */
enum RunStatus: string
{
    case Queued = 'queued';
    case Planning = 'planning';
    case Implementing = 'implementing';
    case Verifying = 'verifying';
    case Reviewing = 'reviewing';
    case Completed = 'completed';
    case NeedsUserDecision = 'needs_user_decision';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * Get the states the run may move to from this one.
     *
     * @return list<RunStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Planning, self::Cancelling, self::Failed],
            self::Planning => [self::Implementing, self::NeedsUserDecision, self::Cancelling, self::Failed],
            self::Implementing => [self::Verifying, self::NeedsUserDecision, self::Cancelling, self::Failed],
            self::Verifying => [self::Reviewing, self::Implementing, self::NeedsUserDecision, self::Cancelling, self::Failed],
            self::Reviewing => [self::Completed, self::Implementing, self::NeedsUserDecision, self::Cancelling, self::Failed],
            self::NeedsUserDecision => [self::Planning, self::Implementing, self::Cancelling],
            self::Cancelling => [self::Cancelled],
            self::Completed, self::Cancelled, self::Failed => [],
        };
    }

    /**
     * Determine if the run may move to the given state.
     */
    public function canTransitionTo(RunStatus $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Determine if the run has ended and can never change again.
     */
    public function finished(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Failed], true);
    }

    /**
     * Determine if a worker holding the run's lease is working in this state.
     * While verifying, the run waits on the verification job instead.
     */
    public function isWorkerOwned(): bool
    {
        return in_array($this, [self::Queued, self::Planning, self::Implementing, self::Reviewing], true);
    }
}
