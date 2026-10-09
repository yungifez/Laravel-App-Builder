<?php

namespace App\Features;

use App\Actions\Operations\SummarizeSpend;
use Carbon\CarbonImmutable;

/**
 * New work paused for the rest of the day, because today's AI spend
 * reached our limit. It is our doing, so the owner is told so, and when
 * they can try again in their own time.
 */
class SpendPause
{
    /**
     * Say why new work is paused, and when it can start again.
     */
    public static function message(): string
    {
        return __('This is our fault: we paused new work to keep our costs in check. Nothing in your app changed. You can try again from :time.', [
            'time' => self::local(app(SummarizeSpend::class)->dailyLimitLiftsAt()),
        ]);
    }

    /**
     * Say a moment in the owner's time zone, as the browser told us it,
     * the way a person would: "12:00 AM tomorrow".
     */
    protected static function local(CarbonImmutable $moment): string
    {
        $cookie = request()->cookie('time_zone');
        $zone = is_string($cookie) && in_array($cookie, timezone_identifiers_list(), true) ? $cookie : (string) config('app.timezone');
        $local = $moment->setTimezone($zone)->settings(['locale' => app()->getLocale()]);
        $today = CarbonImmutable::now($zone);

        $day = match (true) {
            $local->isSameDay($today) => __('today'),
            $local->isSameDay($today->addDay()) => __('tomorrow'),
            default => $local->isoFormat('D MMMM'),
        };

        return $local->isoFormat('LT').' '.$day;
    }
}
