<?php

namespace App\Features;

use App\Enums\RunStatus;
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
     * Why a change stopped, by the start of the message the run kept.
     */
    protected const STOPS = [
        '/^Verification did not pass/' => 'This is our fault: your app\'s checks still failed after I tried to fix them, so I stopped. Nothing in your app changed. Try again, or ask in other words.',
        '/^The review found problems/' => 'This is our fault: when I looked over the change, I found problems I could not fix, so I stopped. Nothing in your app changed. Try again, or ask in other words.',
        '/^The run finished without changing/' => 'This is our fault: I finished without changing anything in your app. Try again, or ask in other words.',
        '/^The checks could not run/' => 'This is our fault: your app\'s checks could not run because of a problem on our side. Nothing in your app changed. Try again.',
        '/^(The run used all|The agent used up)/' => 'This is our fault: this change needed more work than I can do in one go, so I stopped. Nothing in your app changed. Try again, or ask for a smaller part first.',
        '/^No AI provider could take/' => 'This is our fault: the AI service we use is busy right now. Nothing in your app changed. Try again in a few minutes.',
    ];

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
     * Say why a change failed. The owner's own request never fails a change
     * (an unclear one is asked about), so a failure is ours and says so.
     * What went wrong in detail stays with the run for operators.
     */
    public static function failure(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        if (str_starts_with($message, __('This is our fault'))) {
            return $message;
        }

        // A known stop says what happened, so the owner knows whether to
        // try again now, later, or in other words.
        foreach (self::STOPS as $pattern => $said) {
            if (preg_match($pattern, $message) === 1) {
                return __($said);
            }
        }

        return __('This is our fault: something went wrong on our side while I worked on this. Nothing in your app changed. Try again.');
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

        if ($to === RunStatus::Implementing && ($data['reason'] ?? null) === 'kept_trying') {
            return __('You asked me to keep trying, so I went back to fix it');
        }

        if ($to === RunStatus::Implementing && in_array($from, [RunStatus::Implementing, RunStatus::Verifying, RunStatus::Reviewing], true)) {
            return match ($data['reason'] ?? null) {
                'verification_failed' => __('Some checks failed, so I went back to fix them'),
                'tests_not_run' => __('My test would not have been run, so I went back to put it where it will be'),
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
            return match ($data['reason'] ?? null) {
                'question' => __('Asked you a question'),
                'finding_proposed' => __('Asked you whether to keep something the checks found'),
                'verification_interrupted' => __('The checks could not run because of a problem on our side. This is our fault.'),
                default => __('Stopped to ask what you want to do'),
            };
        }

        return match ($to) {
            RunStatus::Planning => $from === RunStatus::NeedsUserDecision ? __('Carried on with your answer') : __('Working out what to change'),
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
