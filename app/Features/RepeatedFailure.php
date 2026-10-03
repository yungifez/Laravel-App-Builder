<?php

namespace App\Features;

use App\Models\FeatureRequest;

/**
 * A try of a change that stopped exactly as the try before it did. Trying
 * once more is then unlikely to help, so the owner is told so and given
 * another way forward instead.
 */
class RepeatedFailure
{
    /**
     * Stops that say on their own when to try again, or that the owner
     * asked for.
     *
     * @var list<string>
     */
    protected const OWN_ADVICE = ['spend_limit', 'usage_limit', 'cancelled'];

    /**
     * Determine if the change stopped for the same reason, with the same
     * first line of error, as the try it tries again.
     */
    public static function of(FeatureRequest $featureRequest): bool
    {
        $run = $featureRequest->latestRun;
        $earlier = $featureRequest->retry_of_id === null ? null : FeatureRequest::query()->find($featureRequest->retry_of_id)?->latestRun;

        if ($run === null || $earlier === null || $run->stop_reason === null || in_array($run->stop_reason, self::OWN_ADVICE, true)) {
            return false;
        }

        $error = self::firstLine($run->error);

        return $run->stop_reason === $earlier->stop_reason && $error !== '' && $error === self::firstLine($earlier->error);
    }

    /**
     * Say why it stopped without the advice to try again, and what to do
     * instead. A reason that gives other advice is kept as it is.
     */
    public static function reason(string $reason): string
    {
        $why = trim((string) preg_replace('/\s*Try again[^.]*\.$/', '', trim($reason)));

        // A stop that does not advise trying again already says what to do.
        if ($why === trim($reason)) {
            return $why;
        }

        return trim($why.' '.__('It stopped the same way last time, so trying again will likely stop the same way. Ask for a smaller part of it in the chat, or ask one of our developers.'));
    }

    protected static function firstLine(?string $error): string
    {
        return trim((string) strtok((string) $error, "\n"));
    }
}
