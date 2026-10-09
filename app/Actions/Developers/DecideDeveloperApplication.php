<?php

namespace App\Actions\Developers;

use App\Models\DeveloperApplication;
use App\Models\DeveloperReview;
use App\Models\User;
use App\Notifications\DeveloperApplicationDecided;

/**
 * An operator lets a person answer owners' questions, says no, or takes
 * that back later. The person hears of a yes or a no once.
 */
class DecideDeveloperApplication
{
    /**
     * Approve the application, or decline it. Declining an approved
     * developer gives back the questions they took and did not answer.
     */
    public function handle(DeveloperApplication $application, User $operator, bool $approve): DeveloperApplication
    {
        $was = $application->approved();

        $application->update([
            'approved_at' => $approve ? ($application->approved_at ?? now()) : $application->approved_at,
            'declined_at' => $approve ? null : now(),
            'decided_by' => $operator->id,
        ]);

        if (! $approve) {
            DeveloperReview::query()
                ->where('claimed_by', $application->user_id)
                ->whereNull('answered_at')
                ->update(['claimed_by' => null, 'claimed_at' => null]);
        }

        if ($was !== $approve) {
            $application->user->notify(new DeveloperApplicationDecided($approve));
        }

        return $application;
    }
}
