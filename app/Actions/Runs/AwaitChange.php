<?php

namespace App\Actions\Runs;

use App\Actions\Changes\AcceptChange;
use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Support\Sleep;

/**
 * Follow a requested change to its end as an owner who trusts the builder
 * would: a question gets its recommended answer. Used by the operator's
 * benchmark and context experiment, which wait for each change in turn.
 */
class AwaitChange
{
    public function __construct(
        private AnswerRunQuestion $answerRunQuestion,
        private AcceptChange $acceptChange,
    ) {}

    /**
     * Wait until the change is done, and keep it when asked to.
     *
     * @return array{outcome: 'completed'|'stopped'|'timed_out', reason: string|null, request: FeatureRequest}
     */
    public function handle(FeatureRequest $request, User $owner, bool $keep): array
    {
        $deadline = now()->addSeconds((int) config('builder.benchmark.wait'));

        while (now()->lessThan($deadline)) {
            $request->refresh();
            $run = $request->latestRun;

            if ($run?->status === RunStatus::NeedsUserDecision && $run->question !== null) {
                $this->answerRunQuestion->handle($request, $owner, $run->question['recommended'] ?? $run->question['options'][0] ?? null);
            } elseif ($run?->status === RunStatus::NeedsUserDecision) {
                // No question: the run gave up and waits for the owner to
                // change the request, which an operator's run never does.
                return ['outcome' => 'stopped', 'reason' => 'It stopped and waits for the owner. '.($run->error ?? ''), 'request' => $request];
            } elseif ($run?->status === RunStatus::Completed) {
                if (! $keep) {
                    return ['outcome' => 'completed', 'reason' => null, 'request' => $request];
                }

                $kept = $this->acceptChange->handle($request, $owner);

                if ($kept->commit_sha !== null) {
                    return ['outcome' => 'completed', 'reason' => null, 'request' => $kept];
                }

                // The app moved on while the change was made: it is built
                // again on top, and that one is kept instead.
                $request = $kept;
            } elseif ($run !== null && in_array($run->status, [RunStatus::Failed, RunStatus::Cancelled], true)) {
                return ['outcome' => 'stopped', 'reason' => "It stopped: {$run->status->value}. ".($run->error ?? $request->error ?? ''), 'request' => $request];
            }

            Sleep::for(10)->seconds();
        }

        return ['outcome' => 'timed_out', 'reason' => 'It took longer than the benchmark waits for one change.', 'request' => $request];
    }
}
