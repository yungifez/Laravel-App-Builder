<?php

namespace App\Actions\Runs;

use App\Actions\Features\RetryFeatureRequest;
use App\Actions\Projects\ConnectOwnTool;
use App\Enums\FeatureRequestStatus;
use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Enums\WorkspaceStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Operations\ExecutionSettings;
use App\Runs\ConstructionDriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KeepTryingRun
{
    /**
     * Stops the owner can ask the change to keep working past: it ran out
     * of attempts to fix what the checks or the second look found, or of
     * turns or time while it built the change.
     */
    public const STOPS = [StopReason::VerificationFailed, StopReason::ReviewFindings, StopReason::BudgetExhausted];

    /**
     * Stops where the AI service, not the change, was the trouble: the run
     * picks up the step it stopped in, with nothing done again.
     */
    public const SERVICE_STOPS = [StopReason::ProvidersUnavailable, StopReason::OutOfCredit, StopReason::RequestRefused];

    public function __construct(
        private TransitionRun $transitionRun,
        private ConstructionDriverManager $drivers,
    ) {}

    /**
     * Determine if the change can go on from where it stopped: it ran out
     * of attempts with its work still there, or the owner stopped it once
     * it had a plan.
     */
    public static function possible(FeatureRequest $featureRequest): bool
    {
        return self::outOfTries($featureRequest) || self::goesOn($featureRequest);
    }

    /**
     * Determine if the change goes on from what it has rather than trying
     * again past a failure: it was stopped, or the AI service let it down.
     */
    public static function goesOn(FeatureRequest $featureRequest): bool
    {
        return self::resumable($featureRequest) || self::serviceStopped($featureRequest);
    }

    /**
     * Determine if the AI service stopped the change while it was planned,
     * built or reviewed, with what it had so far still there.
     */
    protected static function serviceStopped(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        if ($run === null
            || $run->status !== RunStatus::NeedsUserDecision
            || $run->question !== null
            || ! in_array($run->stop_reason, self::SERVICE_STOPS, true)
            || ! RetryFeatureRequest::retryable($featureRequest)) {
            return false;
        }

        return match (self::stoppedIn($run)) {
            RunStatus::Planning, RunStatus::Reviewing => true,
            // Its code so far lives in the workspace.
            RunStatus::Implementing => $run->workspace !== null && $run->workspace->status !== WorkspaceStatus::Destroyed,
            default => false,
        };
    }

    /**
     * Get the step the run was in when it stopped to wait on its owner.
     */
    protected static function stoppedIn(Run $run): ?RunStatus
    {
        $stop = $run->events()->where('type', 'status')->reorder('sequence', 'desc')->first();

        return ($stop?->data['to'] ?? null) === RunStatus::NeedsUserDecision->value
            ? RunStatus::tryFrom((string) ($stop->data['from'] ?? ''))
            : null;
    }

    /**
     * Determine if the change ended after it was planned and before it was
     * kept: the owner stopped it, or it failed on our side. Its workspace
     * is gone, but the plan, the owner's answers and the code made so far
     * are kept, so a new run can go on from them. Only Start over throws
     * that work away, and only when the owner asks.
     */
    public static function resumable(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        return $run !== null
            && in_array($run->status, [RunStatus::Cancelled, RunStatus::Failed], true)
            && $run->plan !== null
            && ($run->plan['answer'] ?? null) === null
            && $featureRequest->commit_sha === null
            && $featureRequest->reverted_at === null
            // Offered only where trying again is: not once it was tried
            // again, nor while a limit would stop it the same way. Nor when
            // the code so far is what broke the checks.
            && RetryFeatureRequest::retryable($featureRequest)
            && ! RetryFeatureRequest::mustBeMadeAgain($featureRequest);
    }

    /**
     * Determine if the change stopped only because it ran out of attempts,
     * with its work so far still there to go on from.
     */
    protected static function outOfTries(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;

        return $run !== null
            && $run->status === RunStatus::NeedsUserDecision
            && $run->question === null
            && in_array($run->stop_reason, self::STOPS, true)
            && self::feedback($run) !== null
            && $run->plan !== null
            // Its work so far must still be there to build on.
            && $run->workspace !== null
            && $run->workspace->status !== WorkspaceStatus::Destroyed;
    }

    /**
     * Get what the next pass must do: what was kept when the change stopped,
     * or, for a change that stopped before that was kept, the same worked
     * out again from its last checks or review. Feedback from an earlier
     * repair says nothing about why it stopped, so it is not used.
     *
     * @return array{reason: string, details: list<string>}|null
     */
    protected static function feedback(Run $run): ?array
    {
        if (($run->feedback['reason'] ?? null) === $run->stop_reason?->value) {
            return $run->feedback;
        }

        $details = match ($run->stop_reason) {
            StopReason::VerificationFailed => ($verification = $run->verifications()->reorder('id', 'desc')->first()) === null
                ? []
                : app(CompleteRunVerification::class)->failures($verification),
            StopReason::ReviewFindings => array_values(array_map(
                fn (array $finding) => trim(($finding['file'] !== null ? "{$finding['file']}: " : '').$finding['summary']),
                array_filter($run->review['findings'] ?? [], fn (array $finding) => $finding['severity'] === 'blocking'),
            )),
            StopReason::BudgetExhausted => [__('You stopped before you finished. Finish the change.')],
            default => [],
        };

        return $details === [] || $run->stop_reason === null ? null : ['reason' => $run->stop_reason->value, 'details' => $details];
    }

    /**
     * Go on from the work so far and fix what stopped it, with as many
     * attempts, turns and time again as it had at the start. The owner decides when a change
     * is worth more work, not a fixed limit.
     *
     * @throws ValidationException when the change did not stop that way.
     */
    public function handle(FeatureRequest $featureRequest): Run
    {
        if (! self::possible($featureRequest)) {
            throw ValidationException::withMessages(['keep_trying' => __('This change did not stop for want of tries, so there is nothing to keep trying.')]);
        }

        if (self::resumable($featureRequest)) {
            return $this->resume($featureRequest);
        }

        $run = $featureRequest->latestRun;

        if (self::serviceStopped($featureRequest)) {
            $this->transitionRun->handle($run, self::stoppedIn($run), attributes: ['error' => null], details: ['reason' => 'went_on', 'stopped' => $run->stop_reason?->value]);

            ExecuteRun::dispatch($run);

            return $run;
        }

        $this->transitionRun->handle($run, RunStatus::Implementing, attributes: [
            'repairs' => $run->repairs + 1,
            'feedback' => self::feedback($run),
            'error' => null,
        ], details: ['reason' => 'kept_trying', 'stopped' => $run->stop_reason?->value]);

        ExecuteRun::dispatch($run);

        return $run;
    }

    /**
     * Start a new run on the same change from its plan, the owner's answers
     * and what they agreed to, straight at building: nothing is planned or
     * asked again. Code made before the stop is laid on the new workspace
     * first, so the build goes on from it.
     */
    protected function resume(FeatureRequest $featureRequest): Run
    {
        return DB::transaction(function () use ($featureRequest) {
            $stopped = $featureRequest->latestRun;
            $theirs = ConnectOwnTool::connected($featureRequest->project);
            $madeSoFar = trim((string) $featureRequest->patch) !== '';

            $run = $featureRequest->runs()->create([
                'driver' => $theirs ? 'worker' : $this->drivers->getDefaultDriver(),
                'config_version' => ExecutionSettings::record(),
                'status' => RunStatus::Implementing,
                'plan' => $stopped->plan,
                'context' => $stopped->context,
                'answers' => $stopped->answers,
                'kept_assumptions' => $stopped->kept_assumptions,
                'question_limit' => $stopped->question_limit,
                'feedback' => $madeSoFar ? [
                    'reason' => 'resumed',
                    'details' => [__('Your earlier attempt stopped before it finished. The code made so far is already in place. Go on from it and finish the change.')],
                ] : null,
            ]);

            $run->recordEvent('created', ['driver' => $run->driver, 'config_version' => $run->config_version]);
            $run->recordEvent('resumed', ['from' => $stopped->id, 'made_so_far' => $madeSoFar]);

            if ($theirs) {
                $run->recordEvent('handed_to_owner', ['driver' => 'worker']);
            }

            $featureRequest->update(['status' => FeatureRequestStatus::Generating, 'error' => null]);

            ExecuteRun::dispatch($run)->afterCommit();

            return $run;
        });
    }
}
