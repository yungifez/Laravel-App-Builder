<?php

namespace App\Notifications;

use App\Enums\RunStatus;
use App\Models\FeatureRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A change the owner asked for needs them: it is ready to try, it has a
 * question, or it did not work.
 */
class ChangeNeedsYou extends Notification
{
    /**
     * Create a new notification instance.
     */
    public function __construct(public FeatureRequest $featureRequest, public RunStatus $status) {}

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
            ->line($this->featureRequest->prompt)
            ->action(__('Open it'), $this->url());
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => match ($this->status) {
                RunStatus::Completed => 'ready',
                RunStatus::NeedsUserDecision => 'question',
                default => 'failed',
            },
            'title' => $this->title(),
            'body' => str($this->featureRequest->prompt)->squish()->limit(120)->toString(),
            'project_id' => $this->featureRequest->project_id,
            'feature_request_id' => $this->featureRequest->id,
            'url' => $this->url(),
        ];
    }

    /**
     * Say what happened in the owner's words.
     */
    protected function title(): string
    {
        return match ($this->status) {
            RunStatus::Completed => __('Your change is ready to try'),
            RunStatus::NeedsUserDecision => __('I have a question about your change'),
            default => __('Your change did not work'),
        };
    }

    /**
     * Get the address of the change in the workspace.
     */
    protected function url(): string
    {
        return route('projects.show', ['project' => $this->featureRequest->project_id, 'change' => $this->featureRequest->id]);
    }
}
