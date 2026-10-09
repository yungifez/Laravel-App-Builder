<?php

namespace App\Notifications;

use App\Models\DeveloperReview;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One of our developers answered what the owner asked them.
 */
class DeveloperAnswered extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public DeveloperReview $review) {}

    /**
     * Get the notification's delivery channels. Email is off unless the
     * operator turns it on.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('builder.notifications.email') ? ['database', 'mail'] : ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('A developer answered your question'))
            ->line($this->review->question)
            ->action(__('Read the answer'), route('projects.developers.index', $this->review->project));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'answered',
            'title' => __('A developer answered your question'),
            'body' => str($this->review->question)->squish()->limit(120)->toString(),
            'project_id' => $this->review->project_id,
            'developer_review_id' => $this->review->id,
        ];
    }
}
