<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An operator said yes or no to a person who asked to answer owners'
 * questions, or took a yes back.
 */
class DeveloperApplicationDecided extends Notification
{
    public function __construct(public bool $approved) {}

    /**
     * Get the notification's delivery channels. The person may have no
     * app with us, so a yes or a no also goes by email.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return $this->approved
            ? (new MailMessage)
                ->subject(__('You can answer owners\' questions now'))
                ->line(__('Owners ask about their apps in their own words. Take a question, read the notes and the code, and write a short answer, what you noticed and guidance.'))
                ->action(__('See the questions'), route('operations.developer-reviews.index'))
            : (new MailMessage)
                ->subject(__('About answering owners\' questions'))
                ->line(__('Thank you for offering. We cannot take you on to answer questions right now.'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'developer',
            'title' => $this->approved ? __('You can answer owners\' questions now') : __('We cannot take you on to answer questions right now'),
            'body' => $this->approved ? __('Take a question when you have an hour for it.') : __('Thank you for offering.'),
            'developer_application' => $this->approved,
        ];
    }
}
