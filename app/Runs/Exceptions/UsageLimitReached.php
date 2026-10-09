<?php

namespace App\Runs\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The owner used all the AI use their plan includes this month.
 */
class UsageLimitReached extends RuntimeException
{
    /**
     * Say so to the owner, with when it starts again and what they can do.
     */
    public static function until(CarbonInterface $resetsAt): self
    {
        return new self(__('You have used all the AI use your plan includes this month. It starts again on :date, or you can move to a bigger plan in Settings. Nothing in your app changed.', [
            'date' => $resetsAt->isoFormat('D MMMM'),
        ]));
    }
}
