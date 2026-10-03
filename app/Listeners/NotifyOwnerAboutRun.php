<?php

namespace App\Listeners;

use App\Enums\RunStatus;
use App\Events\RunStatusChanged;
use App\Features\OwnerWording;
use App\Models\FeatureRequest;
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

        // A background tidy-up is kept or put aside on its own.
        if ($featureRequest === null || $owner === null || $featureRequest->tidy !== null) {
            return;
        }

        // One note per change: a newer one replaces any the owner has not
        // read. Trying a stopped change again is the same change.
        $tries = $this->tries($featureRequest);
        $owner->unreadNotifications()
            ->where('type', ChangeNeedsYou::class)
            ->get()
            ->filter(fn ($notification) => in_array($notification->data['feature_request_id'] ?? null, $tries, true))
            ->each->delete();

        // A run stops for the owner without a question when it could not
        // finish; that is told as a change that did not work.
        $status = $event->to === RunStatus::NeedsUserDecision && $event->run->question === null
            ? RunStatus::Failed
            : $event->to;

        $owner->notify(new ChangeNeedsYou($featureRequest, $status, $status === RunStatus::Failed ? $this->reason($event->run->error) : null));
    }

    /**
     * Say briefly why the change did not work: whose fault it is, what
     * happened and what to do. A note has little room, and a change that
     * did not work never changed the app, so that goes unsaid.
     */
    protected function reason(?string $error): string
    {
        $reason = OwnerWording::failure($error) ?? OwnerWording::failure('unknown');

        return str((string) $reason)->replace(' '.__('Nothing in your app changed.'), '')->toString();
    }

    /**
     * Get the change and every earlier try of it that it tries again.
     *
     * @return list<int>
     */
    protected function tries(FeatureRequest $featureRequest): array
    {
        $tries = [$featureRequest->id];

        while ($featureRequest->retry_of_id !== null && ! in_array($featureRequest->retry_of_id, $tries, true)) {
            $tries[] = $featureRequest->retry_of_id;
            $featureRequest = FeatureRequest::query()->find($featureRequest->retry_of_id);

            if ($featureRequest === null) {
                break;
            }
        }

        return $tries;
    }
}
