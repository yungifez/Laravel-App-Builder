<?php

namespace App\Listeners;

use App\Enums\RunStatus;
use App\Events\RunStatusChanged;
use App\Notifications\ChangeNeedsYou;

class NotifyOwnerAboutRun
{
    /**
     * Tell the person who asked for a change when it needs them: it is
     * ready to try, it has a question, or it did not work. A change takes
     * minutes, so they are likely doing something else by then.
     */
    public function handle(RunStatusChanged $event): void
    {
        if (! in_array($event->to, [RunStatus::Completed, RunStatus::NeedsUserDecision, RunStatus::Failed], true)) {
            return;
        }

        $featureRequest = $event->run->featureRequest;
        $owner = $featureRequest?->user;

        if ($featureRequest === null || $owner === null) {
            return;
        }

        // One note per change: a newer one replaces any the owner has not read.
        $owner->unreadNotifications()
            ->where('type', ChangeNeedsYou::class)
            ->get()
            ->filter(fn ($notification) => ($notification->data['feature_request_id'] ?? null) === $featureRequest->id)
            ->each->delete();

        $owner->notify(new ChangeNeedsYou($featureRequest, $event->to));
    }
}
