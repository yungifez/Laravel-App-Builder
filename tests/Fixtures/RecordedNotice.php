<?php

namespace Tests\Fixtures;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A notification of the app RecordedApp stands in for.
 */
class RecordedNotice extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(protected array $channels = ['mail']) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line('Recorded');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
