<?php

namespace App\Notifications;

use App\Models\HealthCheck;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The scheduled package lookup found a known security problem the app's
 * last check did not know of. The owner fixes it from what their app is.
 */
class PackagesNeedYou extends Notification
{
    /**
     * Create a new notification instance.
     *
     * @param  list<string>  $packages  The packages with problems new since the last check
     */
    public function __construct(public HealthCheck $healthCheck, public array $packages) {}

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
            ->subject($this->title())
            ->line($this->body())
            ->action(__('Open it'), route('projects.understanding.show', $this->healthCheck->project));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'packages',
            'title' => $this->title(),
            'body' => $this->body(),
            'reason' => null,
            'project_id' => $this->healthCheck->project_id,
            'health_check_id' => $this->healthCheck->id,
        ];
    }

    /**
     * Say what happened in the owner's words.
     */
    protected function title(): string
    {
        return __('A package your app uses has a new security problem');
    }

    /**
     * Name the packages, so the owner knows what the fix is about.
     */
    protected function body(): string
    {
        return str(implode(', ', $this->packages))->limit(120)->toString();
    }
}
