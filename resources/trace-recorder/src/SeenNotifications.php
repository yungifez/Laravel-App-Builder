<?php

namespace TraceRecorder;

use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\NotificationFake;

/**
 * Stands in for a test's notification fake (see Fakes): it tells the
 * recorder of each notification the fake takes, then does what the fake
 * does.
 */
class SeenNotifications extends NotificationFake
{
    public ?Recorder $traceRecorder = null;

    public function sendNow(...$arguments)
    {
        $notification = $arguments[1] ?? null;

        if ($this->traceRecorder === null || ! array_is_list($arguments) || ! is_object($notification)) {
            return parent::sendNow(...$arguments);
        }

        $notifiables = $arguments[0] instanceof Collection || is_array($arguments[0]) ? $arguments[0] : [$arguments[0]];

        // The fake decides which channels each person gets, so it takes
        // them one at a time, and what it kept says what was sent.
        foreach ($notifiables as $notifiable) {
            $before = count($this->taken($notifiable, $notification));
            parent::sendNow([$notifiable], $notification, ...array_slice($arguments, 2));
            $taken = $this->taken($notifiable, $notification);

            if (count($taken) > $before) {
                $this->traceRecorder->notified($notification, (array) (end($taken)['channels'] ?? []));
            }
        }
    }

    /**
     * Get what the fake keeps of one notification to one person.
     *
     * @return list<array<string, mixed>>
     */
    protected function taken(mixed $notifiable, object $notification): array
    {
        if (! is_object($notifiable) || ! method_exists($notifiable, 'getKey')) {
            return [];
        }

        return $this->notifications[$notifiable::class][(string) $notifiable->getKey()][$notification::class] ?? [];
    }
}
