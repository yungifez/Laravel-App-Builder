<?php

namespace App\Actions\Developers;

use App\Models\DeveloperReview;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A developer takes an owner's question, so nobody else spends their hour
 * on it, or gives it back for someone else to take.
 */
class ClaimDeveloperReview
{
    /**
     * Take the question. Two developers pressing at once: one gets it.
     *
     * @throws ValidationException when someone took it first.
     */
    public function handle(DeveloperReview $review, User $developer): DeveloperReview
    {
        $taken = DeveloperReview::query()
            ->whereKey($review->id)
            ->whereNull('claimed_by')
            ->whereNull('answered_at')
            ->whereNull('withdrawn_at')
            ->update(['claimed_by' => $developer->id, 'claimed_at' => now()]);

        if ($taken === 0) {
            throw ValidationException::withMessages(['claim' => __('Someone else took this question first.')]);
        }

        return $review->refresh();
    }

    /**
     * Give the question back before answering it.
     */
    public function release(DeveloperReview $review): DeveloperReview
    {
        $review->update(['claimed_by' => null, 'claimed_at' => null]);

        return $review;
    }
}
