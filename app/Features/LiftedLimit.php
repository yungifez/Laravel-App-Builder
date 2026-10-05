<?php

namespace App\Features;

use App\Actions\Features\RetryFeatureRequest;
use App\Actions\Runs\KeepTryingRun;
use App\Enums\StopReason;
use App\Models\FeatureRequest;

/**
 * A change stopped by a limit, said as the limit stands now. The daily cost
 * pause says when it lifts, and once it or the plan's monthly use has
 * lifted, that "Try again" works now. A change that used what one try may
 * spend can go on from its work so far, when that work is still there.
 */
class LiftedLimit
{
    /**
     * Say why the change stopped, as it stands now.
     */
    public static function reason(FeatureRequest $featureRequest, ?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $lifted = RetryFeatureRequest::retryable($featureRequest);

        return match ($featureRequest->latestRun?->stop_reason) {
            StopReason::SpendLimit => $lifted
                ? __('This is our fault: we paused new work for a day to keep our costs in check. That pause is over, so you can try again now. Nothing in your app changed.')
                : SpendPause::message(),
            StopReason::UsageLimit => $lifted
                ? __('This stopped because your plan\'s AI use for the month ran out. It has started again, so you can try again now. Nothing in your app changed.')
                : $reason,
            StopReason::BudgetExhausted => $lifted && KeepTryingRun::possible($featureRequest)
                ? __('This is our fault: this change needed more work than I can do in one go, so I stopped. Nothing in your app changed. Keep trying to go on from where I stopped, or ask for a smaller part first.')
                : $reason,
            default => $reason,
        };
    }
}
