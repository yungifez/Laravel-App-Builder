<?php

namespace App\Features;

use App\Actions\Features\RetryFeatureRequest;
use App\Models\FeatureRequest;

/**
 * A change stopped by a limit that has since lifted: the daily cost pause
 * or the plan's monthly use. Its stop said to wait, but "Try again" works
 * now, so the owner is told the wait is over.
 */
class LiftedLimit
{
    /**
     * Say why the change stopped, as it stands now.
     */
    public static function reason(FeatureRequest $featureRequest, ?string $reason): ?string
    {
        if ($reason === null || ! RetryFeatureRequest::retryable($featureRequest)) {
            return $reason;
        }

        return match ($featureRequest->latestRun?->stop_reason) {
            'spend_limit' => __('This is our fault: we paused new work for a day to keep our costs in check. That pause is over, so you can try again now. Nothing in your app changed.'),
            'usage_limit' => __('This stopped because your plan\'s AI use for the month ran out. It has started again, so you can try again now. Nothing in your app changed.'),
            default => $reason,
        };
    }
}
