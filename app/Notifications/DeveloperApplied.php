<?php

namespace App\Notifications;

use App\Models\DeveloperApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Someone asked to answer owners' questions as a developer. The operators
 * hear, since one of them decides.
 */
class DeveloperApplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public DeveloperApplication $application) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':name wants to answer questions as a developer', ['name' => $this->application->user->name]))
            ->line($this->application->about)
            ->line($this->application->link ?? '')
            ->action(__('Decide'), route('operations.developers.index'));
    }
}
