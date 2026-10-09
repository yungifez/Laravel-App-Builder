<?php

namespace App\Features;

use App\Enums\StopReason;
use App\Models\FeatureRequest;

/**
 * A try of a change that stopped exactly as the try before it did. Trying
 * once more is then unlikely to help, so the owner is told so and given
 * another way forward instead.
 */
class RepeatedFailure
{
    /**
     * What to do instead when a stop repeats, for stops whose own advice
     * is to try again.
     *
     * @var array<string, string>
     */
    public const ADVICE = [
        StopReason::ConstructionFailed->value => 'Ask for a smaller part of it in the chat, or ask one of our developers.',
        StopReason::CannotGenerate->value => 'Ask for it in other words in the chat, or ask one of our developers.',
        StopReason::WorkerStopped->value => 'Ask one of our developers to look at it.',
        StopReason::BudgetExhausted->value => 'Ask for a smaller part of it in the chat, or ask one of our developers.',
        StopReason::WrittenTestsChanged->value => 'Ask one of our developers to look at it.',
        StopReason::NoChanges->value => 'Say in other words what should change, or ask one of our developers.',
        StopReason::ReviewFindings->value => 'Ask for a smaller part of it in the chat, or ask one of our developers.',
        StopReason::VerificationFailed->value => 'Ask for a smaller part of it in the chat, or ask one of our developers.',
        StopReason::VerificationInterrupted->value => 'Ask one of our developers to look at it.',
        StopReason::WrittenTestStillFails->value => 'Say more about what you asked for in the chat, or ask one of our developers.',
        // Ours to put right: one more try may find it fixed, a third will
        // not. What the owner asked for stays here meanwhile.
        StopReason::OutOfCredit->value => 'We are fixing it on our side. What you asked for stays here, so you can try it again later, or ask one of our developers.',
        StopReason::RequestRefused->value => 'We are fixing it on our side. What you asked for stays here, so you can try it again later, or ask one of our developers.',
    ];

    /**
     * Stops never called a repeat, each with why.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        StopReason::SpendLimit->value => 'It says when the pause lifts.',
        StopReason::UsageLimit->value => 'It says when the plan starts again, and how to get more.',
        StopReason::WorkerLapsed->value => 'The owner\'s own coding tool ran out of time; the change itself did not fail.',
        StopReason::ProvidersUnavailable->value => 'A busy AI service passes on its own, so trying again later is right.',
        StopReason::Question->value => 'Nothing failed; the owner answers.',
        StopReason::FindingProposed->value => 'Nothing failed; the owner answers.',
        StopReason::Cancelled->value => 'The owner asked for it.',
    ];

    /**
     * Determine if the change stopped for the same reason, with the same
     * first line of error, as the try it tries again.
     */
    public static function of(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;
        $earlier = $featureRequest->retry_of_id === null ? null : FeatureRequest::query()->find($featureRequest->retry_of_id)?->latestRun;

        if ($run === null || $earlier === null || $run->stop_reason === null || ! isset(self::ADVICE[$run->stop_reason->value])) {
            return false;
        }

        $error = self::firstLine($run->error);

        return $run->stop_reason === $earlier->stop_reason && $error !== '' && $error === self::firstLine($earlier->error);
    }

    /**
     * Say why it stopped without the advice to try again, and what to do
     * instead. A reason that gives other advice is kept as it is.
     */
    public static function reason(string $reason, StopReason $stop): string
    {
        $why = trim((string) preg_replace('/\s*Try again[^.]*\.$/', '', trim($reason)));

        // A stop that does not advise trying again already says what to do.
        if ($why === trim($reason)) {
            return $why;
        }

        return trim($why.' '.__('It stopped the same way last time, so trying again will likely stop the same way.').' '.__(self::ADVICE[$stop->value] ?? self::ADVICE[StopReason::ConstructionFailed->value]));
    }

    protected static function firstLine(?string $error): string
    {
        return trim((string) strtok((string) $error, "\n"));
    }
}
