<?php

namespace App\Listeners;

use App\Actions\Billing\MeasureUsage;
use App\Enums\RunStatus;
use App\Events\RunStatusChanged;
use App\Notifications\UseRunningOut;

class WarnOwnerAboutUse
{
    /**
     * The shares of a month's use at which the owner hears, highest first.
     */
    protected const LEVELS = [100, 80];

    public function __construct(private MeasureUsage $measureUsage) {}

    /**
     * Tell the owner when a change brings them to most or all of their
     * month's use, once for each level each month, so running out never
     * comes as a surprise.
     */
    public function handle(RunStatusChanged $event): void
    {
        if (! in_array($event->to, [RunStatus::Completed, RunStatus::NeedsUserDecision, RunStatus::Failed], true)) {
            return;
        }

        $owner = $event->run->featureRequest?->user;

        if ($owner === null) {
            return;
        }

        $usage = $this->measureUsage->handle($owner);
        $level = collect(self::LEVELS)->first(fn (int $level) => $usage['percent'] >= $level);

        if ($usage['unlimited'] || $level === null) {
            return;
        }

        $month = $usage['started_at']->toDateString();
        $told = $owner->notifications()
            ->where('type', UseRunningOut::class)
            ->get()
            ->contains(fn ($notification) => ($notification->data['month'] ?? null) === $month && ($notification->data['level'] ?? 0) >= $level);

        if (! $told) {
            $owner->notify(new UseRunningOut($level, $month, $usage['resets_at']->isoFormat('D MMMM')));
        }
    }
}
