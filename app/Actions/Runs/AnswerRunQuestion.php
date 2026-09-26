<?php

namespace App\Actions\Runs;

use App\Actions\Context\RecordDecision;
use App\Enums\RunStatus;
use App\Jobs\ExecuteRun;
use App\Models\FeatureRequest;
use App\Models\Run;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AnswerRunQuestion
{
    public function __construct(private TransitionRun $transitionRun, private RecordDecision $recordDecision) {}

    /**
     * Take the owner's answer to the question the run waits on and plan
     * again with it. A null answer means "you decide": the recommended
     * option is used, and it stays an assumption instead of a decision.
     * Asking for more questions lets the planner ask about the rest, one at
     * a time.
     *
     * @throws ValidationException when nothing waits for an answer or the answer is not an option.
     */
    public function handle(FeatureRequest $featureRequest, User $owner, ?string $answer, bool $moreQuestions = false): Run
    {
        $run = $featureRequest->latestRun;
        $question = $run?->question;

        if ($run === null || $question === null || $run->status !== RunStatus::NeedsUserDecision) {
            throw ValidationException::withMessages(['answer' => __('There is no question waiting for an answer.')]);
        }

        if ($answer !== null && ! in_array($answer, $question['options'], true)) {
            throw ValidationException::withMessages(['answer' => __('Pick one of the answers.')]);
        }

        $decidedBy = $answer === null ? 'builder' : 'owner';
        $answer ??= $question['recommended'] ?? $question['options'][0];

        if ($decidedBy === 'owner') {
            // The decision belongs in the notes, but failing to write it must
            // not lose the answer: the run still carries it.
            rescue(fn () => $this->recordDecision->handle($featureRequest->project, $owner, $question['text'], $answer));
        }

        $this->transitionRun->handle($run, RunStatus::Planning, attributes: [
            'question' => null,
            'answers' => [...($run->answers ?? []), ['question' => $question['text'], 'answer' => $answer, 'decided_by' => $decidedBy]],
            'question_limit' => $moreQuestions
                ? max($run->question_limit, (int) config('builder.construction.questions.when_asked_for_more'))
                : $run->question_limit,
        ], details: ['reason' => 'answered', 'decided_by' => $decidedBy]);

        ExecuteRun::dispatch($run);

        return $run;
    }
}
