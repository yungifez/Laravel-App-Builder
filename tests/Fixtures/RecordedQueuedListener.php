<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A listener of the app RecordedApp stands in for that waits on a queue.
 * It catches an email that cannot be sent and ends as if the email was
 * sent.
 */
class RecordedQueuedListener implements ShouldQueue
{
    public const PATH = 'tests/Fixtures/RecordedQueuedListener.php';

    public function handle(RecordedEvent $event): void
    {
        try {
            Mail::raw('Shipped', fn ($message) => $message->to('owner@example.com'));
        } catch (Throwable) {
            //
        }
    }
}
