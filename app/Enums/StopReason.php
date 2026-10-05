<?php

namespace App\Enums;

/**
 * Why a run failed, waits on its owner, or was cancelled. Every stop the
 * code records is one of these, and each says whose fault it is and what
 * the owner can do next (StopReasonTest holds every one to that).
 */
enum StopReason: string
{
    // The run failed.
    case ConstructionFailed = 'construction_failed';
    case CannotGenerate = 'cannot_generate';
    case SpendLimit = 'spend_limit';
    case UsageLimit = 'usage_limit';
    case WorkerLapsed = 'worker_lapsed';
    case WorkerStopped = 'worker_stopped';

    // The run waits on its owner.
    case BudgetExhausted = 'budget_exhausted';
    case ProvidersUnavailable = 'providers_unavailable';
    case OutOfCredit = 'out_of_credit';
    case RequestRefused = 'request_refused';
    case Question = 'question';
    case FindingProposed = 'finding_proposed';
    case WrittenTestsChanged = 'written_tests_changed';
    case NoChanges = 'no_changes';
    case ReviewFindings = 'review_findings';
    case VerificationFailed = 'verification_failed';
    case VerificationInterrupted = 'verification_interrupted';
    case WrittenTestStillFails = 'written_test_still_fails';

    // The owner asked it to stop.
    case Cancelled = 'cancelled';

    /**
     * Determine if the stop is our fault. The owner's plan, the owner's own
     * coding tool, a question and the owner's own cancel are not.
     */
    public function ours(): bool
    {
        return ! in_array($this, [self::UsageLimit, self::WorkerLapsed, self::Question, self::FindingProposed, self::Cancelled], true);
    }

    /**
     * Get what the owner can do about the stop.
     */
    public function nextStep(): NextStep
    {
        return match ($this) {
            self::UsageLimit => NextStep::Settings,
            self::Question, self::FindingProposed => NextStep::Answer,
            default => NextStep::Retry,
        };
    }

    /**
     * Say the stop to the owner: whose fault it is, what happened and what
     * to do next. A run whose message is already said for the owner keeps
     * it (see OwnerWording::failure).
     */
    public function said(): string
    {
        return match ($this) {
            self::ConstructionFailed => __('This is our fault: something went wrong on our side while I worked on this. Nothing in your app changed. Try again.'),
            self::CannotGenerate => __('This is our fault: I could not make this change here. Nothing in your app changed. Try again, or ask in other words.'),
            self::SpendLimit => __('This is our fault: we paused new work to keep our costs in check. Nothing in your app changed. Try again tomorrow.'),
            self::UsageLimit => __('You have used all the AI use your plan includes this month. You can move to a bigger plan in Settings. Nothing in your app changed.'),
            self::WorkerLapsed => __('Your own coding tool did not hand this change back in time, so I stopped it. Nothing in your app changed. Try again when your tool is ready.'),
            self::WorkerStopped => __('This is our fault: something on our side stopped while I worked on this. Nothing in your app changed. Try again.'),
            self::BudgetExhausted => __('This is our fault: this change needed more work than I can do in one go, so I stopped. Nothing in your app changed. Try again, or ask for a smaller part first.'),
            self::ProvidersUnavailable => __('This is our fault: the AI service we use is busy right now. Nothing in your app changed. Try again in a few minutes.'),
            self::OutOfCredit => __('This is our fault: our account with the AI service we use cannot take more work right now. Nothing in your app changed. Try again later.'),
            self::RequestRefused => __('This is our fault: the AI service we use could not accept how we asked it. We have been told. Nothing in your app changed. Try again later.'),
            self::Question => __('I need your answer to a question before I go on. Nothing in your app changed yet.'),
            self::FindingProposed => __('I asked you about something the checks found. Read it in how we know the change works, and answer.'),
            self::WrittenTestsChanged => __('This is our fault: the tool making this change changed the tests written to check it, so the change proves nothing. Nothing in your app changed. Try again.'),
            self::NoChanges => __('This is our fault: I finished without changing anything in your app. Try again, or ask in other words.'),
            self::ReviewFindings => __('This is our fault: when I looked over the change, I found problems I could not fix, so I stopped. Nothing in your app changed. Try again, or ask in other words.'),
            self::VerificationFailed => __('This is our fault: your app\'s checks still failed after I tried to fix them, so I stopped. Nothing in your app changed. Try again, or ask in other words.'),
            self::VerificationInterrupted => __('This is our fault: your app\'s checks could not run because of a problem on our side. Nothing in your app changed. Try again.'),
            self::WrittenTestStillFails => __('This is our fault: a test I wrote before the work began still fails the same way after I corrected it once, so I stopped. Nothing in your app changed. Try again, or say more about what you asked for.'),
            self::Cancelled => __('You stopped this change, so nothing in your app changed. Try again when you want it.'),
        };
    }
}
