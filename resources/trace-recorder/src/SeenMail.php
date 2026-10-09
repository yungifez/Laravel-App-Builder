<?php

namespace TraceRecorder;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Testing\Fakes\MailFake;

/**
 * Stands in for a test's mail fake (see Fakes): it tells the recorder of
 * each email the app sends or queues, then does what the fake does.
 */
class SeenMail extends MailFake
{
    public ?Recorder $traceRecorder = null;

    public function send(...$arguments)
    {
        $view = $arguments[0] ?? $arguments['view'] ?? null;
        $data = $arguments[1] ?? $arguments['data'] ?? [];

        // An email that goes on the queue is noted when the fake queues it.
        if (! $view instanceof ShouldQueue) {
            $this->traceRecorder?->mailed(is_object($view) ? $view::class : (string) ($data['__laravel_notification'] ?? 'message'));
        }

        return parent::send(...$arguments);
    }

    public function sendNow(...$arguments)
    {
        $mailable = $arguments[0] ?? $arguments['mailable'] ?? null;
        $this->traceRecorder?->mailed(is_object($mailable) ? $mailable::class : 'message');

        return parent::sendNow(...$arguments);
    }

    public function queue(...$arguments)
    {
        $view = $arguments[0] ?? $arguments['view'] ?? null;

        if ($view instanceof Mailable) {
            $this->traceRecorder?->queued($view);
        }

        return parent::queue(...$arguments);
    }
}
