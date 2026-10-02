<?php

namespace App\Actions\Features;

use App\Actions\Runs\TransitionRun;
use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The owner's answer to the agent's case for keeping what a rule found
 * (direction 33). Yes keeps it this way, as "I want it this way" does. No
 * means it must be fixed, and the agent cannot ask again for this change.
 * A change that stopped to ask goes on once nothing is left to answer: its
 * review runs again, so what the owner agreed to no longer holds it back
 * and what they refused is sent back to be fixed.
 */
class AnswerFindingProposals
{
    /**
     * Why a run stops for the owner's answer.
     */
    public const STOP = 'finding_proposed';

    public function __construct(
        private ProposeFindings $proposeFindings,
        private TransitionRun $transitionRun,
    ) {}

    /**
     * Answer every open case of one rule in the change.
     *
     * @throws ValidationException when the change is kept or nothing of the rule is open.
     */
    public function handle(FeatureRequest $featureRequest, string $kind, bool $agreed, User $user): void
    {
        $open = $featureRequest->findingProposals()->where('kind', $kind)->whereNull('agreed');

        if ($featureRequest->isAccepted() || ! $open->exists()) {
            throw ValidationException::withMessages([
                'kind' => __('There is nothing about this left to answer.'),
            ]);
        }

        // Yes covers what the agent asked about, by what each finding is.
        if ($agreed) {
            foreach ((clone $open)->pluck('identity') as $identity) {
                $featureRequest->acceptedFindings()->firstOrCreate(['identity' => $identity], ['kind' => $kind, 'user_id' => $user->id]);
            }
        }

        $open->update(['agreed' => $agreed, 'answered_by' => $user->id, 'answered_at' => now()]);

        $run = $featureRequest->latestRun;

        if ($run?->status === RunStatus::NeedsUserDecision && $run->stop_reason === self::STOP && $this->proposeFindings->pending($featureRequest) === []) {
            $this->transitionRun->handle($run, RunStatus::Reviewing, attributes: ['error' => null], details: ['reason' => 'owner_answered']);

            ExecuteRun::dispatch($run);
        }
    }
}
