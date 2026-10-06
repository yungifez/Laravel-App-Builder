<?php

namespace App\Features;

use App\Enums\RunStatus;
use App\Enums\StopReason;
use App\Models\RunEvent;

/**
 * Says what happened to a change in the owner's words. How changes are made
 * (the models and providers, workers, workspaces, tools, budgets and our
 * own checks) is ours, so it never reaches the owner's browser: messages
 * that name it are replaced with a vague one, and log entries about it are
 * left out.
 */
class OwnerWording
{
    /**
     * Words that only appear in messages about how changes are made.
     */
    protected const INTERNAL = '/\b(planner|coder|reviewer|models?|providers?|drivers?|workers?|lease|fencing|tokens?|adapters?|agents?|prompts?|budgets?|operations?|tools?|workspaces?|platform|builder|control plane|acceptance|reference solutions?|patch(es)?|containers?|docker|runner)\b/i';

    /**
     * Keep a message the owner can read, or replace one that shows how
     * changes are made.
     */
    public static function message(?string $message, ?string $instead = null): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        return preg_match(self::INTERNAL, $message) === 1
            ? ($instead ?? __('Something went wrong on our side while I worked on this.'))
            : $message;
    }

    /**
     * Say why a change stopped. The owner's own request never fails a change
     * (an unclear one is asked about), so a stop says whose fault it is and
     * what to do next. What went wrong in detail stays with the run for
     * operators.
     */
    public static function failure(?string $message, ?StopReason $stop): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        // Already said for the owner: a fault of ours, a plan's limit (the
        // owner's to act on, saying when it lifts), or the app's own code
        // stopping its setup. Only the first line is the owner's; what
        // follows is for operators.
        foreach ([__('This is our fault'), __('You have used all the AI use'), __('Something in your app\'s code')] as $start) {
            if (str_starts_with($message, $start)) {
                return trim((string) strtok($message, "\n"));
            }
        }

        // Why it stopped says what happened, so the owner knows whether to
        // try again now, later, or in other words.
        return $stop?->said() ?? StopReason::ConstructionFailed->said();
    }

    /**
     * Describe one entry of a run's log, or null when it is about how the
     * change is made rather than what happened to it.
     */
    public static function event(RunEvent $event): ?string
    {
        $data = $event->data ?? [];

        return self::text(match ($event->type) {
            'created' => __('You asked for this'),
            'resumed' => ($data['made_so_far'] ?? false) ? __('You asked me to go on, so I started from the work so far') : __('You asked me to go on, so I started from the plan'),
            'status' => self::status($data),
            'review' => ($data['approved'] ?? false) ? __('The change looks right') : __('I found something to fix'),
            'change_accepted' => __('You kept this change'),
            'change_reverted' => __('You undid this change'),
            'verification_retried' => __('Something on our side stopped the checks, so I started them again'),
            default => null,
        });
    }

    /**
     * Describe a move from one state to the next.
     *
     * @param  array<string, mixed>  $data
     */
    protected static function status(array $data): mixed
    {
        $from = RunStatus::tryFrom((string) ($data['from'] ?? ''));
        $to = RunStatus::tryFrom((string) ($data['to'] ?? ''));

        if (($data['reason'] ?? null) === 'went_on') {
            return __('You asked me to go on, so I picked up where I stopped');
        }

        if ($to === RunStatus::Implementing && ($data['reason'] ?? null) === 'kept_trying') {
            return __('You asked me to keep trying, so I went back to fix it');
        }

        if ($to === RunStatus::Implementing && in_array($from, [RunStatus::Implementing, RunStatus::Verifying, RunStatus::Reviewing], true)) {
            return match ($data['reason'] ?? null) {
                'verification_failed' => __('Some checks failed, so I went back to fix them'),
                'tests_not_run' => __('My test would not have been run, so I went back to put it where it will be'),
                'written_tests_changed' => __('The tests written to check the change were changed, so it went back to leave them as they are'),
                'written_test_wrong' => __('A test written before the work began kept failing the same way, so I am correcting it once'),
                'written_test_rewritten' => __('I corrected a test written before the work began, and went back to the change'),
                'review_findings' => __('Went back to fix what I found'),
                default => __('Went back to improve the change'),
            };
        }

        if ($to === RunStatus::Reviewing && ($data['reason'] ?? null) === 'owner_answered') {
            return __('Carried on with your answer');
        }

        if ($to === RunStatus::Reviewing && ($data['reason'] ?? null) === 'failed_before') {
            return __('The change broke nothing that worked before. Looking over what changed');
        }

        if ($to === RunStatus::Reviewing && ($data['verification'] ?? null) === 'passed') {
            return __('The checks passed. Looking over what changed');
        }

        if ($to === RunStatus::Completed && ($data['reason'] ?? null) === 'answered') {
            return __('Answered your question');
        }

        if ($to === RunStatus::NeedsUserDecision) {
            return match (StopReason::tryFrom((string) ($data['reason'] ?? ''))) {
                StopReason::Question => __('Asked you a question'),
                StopReason::FindingProposed => __('Asked you whether to keep something the checks found'),
                StopReason::VerificationInterrupted => __('The checks could not run because of a problem on our side. This is our fault.'),
                // Nothing to decide: the owner only tries again.
                StopReason::ProvidersUnavailable => __('Stopped because the AI service we use could not take the work. This is our fault.'),
                StopReason::OutOfCredit => __('Stopped because our account with the AI service is out of credit. This is our fault.'),
                StopReason::RequestRefused => __('Stopped because the AI service could not accept how we asked it. This is our fault.'),
                StopReason::WrittenTestsChanged => __('Stopped because the tool making the change changed the tests written to check it'),
                StopReason::WrittenTestStillFails => __('Stopped because a test written before the work began still fails after it was corrected once'),
                default => __('Stopped to ask what you want to do'),
            };
        }

        return match ($to) {
            RunStatus::Planning => $from === RunStatus::NeedsUserDecision ? __('Carried on with your answer') : __('Working out what you need'),
            RunStatus::Implementing => __('Making the change'),
            RunStatus::Verifying => __('Checking it works'),
            RunStatus::Reviewing => __('Looking over what changed'),
            RunStatus::Completed => __('Ready for you'),
            RunStatus::Cancelling => __('Stopping'),
            RunStatus::Cancelled => __('Stopped'),
            RunStatus::Failed => __('Could not finish'),
            default => null,
        };
    }

    /**
     * Keep a translated line, which is always a string for these keys.
     */
    protected static function text(mixed $text): ?string
    {
        return is_string($text) ? $text : null;
    }
}
