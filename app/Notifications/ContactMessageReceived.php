<?php

namespace App\Notifications;

use App\Models\ContactMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContactMessageReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ContactMessage $contactMessage) {}

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
     * Get the mail representation of the notification. A reply goes
     * straight to the sender.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Message from :name', ['name' => $this->contactMessage->name]))
            ->replyTo($this->contactMessage->email, $this->contactMessage->name)
            ->line(__(':name (:email) wrote:', ['name' => $this->contactMessage->name, 'email' => $this->contactMessage->email]))
            ->line($this->contactMessage->message);
    }
}
