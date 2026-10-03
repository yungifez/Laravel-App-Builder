<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The owner has used most or all of the AI use their plan includes this
 * month.
 */
class UseRunningOut extends Notification
{
    /**
     * @param  int  $level  The share of the month's use reached, as 80 or 100
     * @param  string  $month  The day the month of use started
     * @param  string  $resetsOn  When use starts again, as the owner reads it
     */
    public function __construct(public int $level, public string $month, public string $resetsOn) {}

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
            ->action(__('See your plan'), route('billing.edit'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'usage',
            'title' => $this->title(),
            'body' => $this->body(),
            'usage' => true,
            'level' => $this->level,
            'month' => $this->month,
        ];
    }

    /**
     * Say how much is used.
     */
    protected function title(): string
    {
        return $this->level >= 100
            ? __('You have used all of this month\'s AI use')
            : __('You have used :level% of this month\'s AI use', ['level' => $this->level]);
    }

    /**
     * Say what happens next.
     */
    protected function body(): string
    {
        return $this->level >= 100
            ? __('New changes wait until :date, or you can move to a bigger plan.', ['date' => $this->resetsOn])
            : __('It starts again on :date. A bigger plan includes more.', ['date' => $this->resetsOn]);
    }
}
