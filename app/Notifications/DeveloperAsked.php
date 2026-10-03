<?php

namespace App\Notifications;

use App\Models\DeveloperReview;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An owner asked one of our developers to look at their app. Every
 * operator and approved developer hears, so the question never waits
 * unseen.
 */
class DeveloperAsked extends Notification
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
            ->subject(__('An owner asked a developer: :app', ['app' => $this->review->project->name]))
            ->line($this->review->question)
            ->action(__('Answer it'), route('operations.developer-reviews.show', $this->review));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'question',
            'title' => __('An owner asked a developer: :app', ['app' => $this->review->project->name]),
            'body' => str($this->review->question)->squish()->limit(120)->toString(),
            'project_id' => $this->review->project_id,
            'asked_review_id' => $this->review->id,
        ];
    }
}
