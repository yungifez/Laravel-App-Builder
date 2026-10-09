<?php

namespace Tests\Unit;

use App\Previews\PreviewClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PreviewClockTest extends TestCase
{
    public function test_a_jump_goes_by_the_calendar_in_the_apps_timezone()
    {
        $this->assertSame('2027-02-28T09:00:00+00:00', PreviewClock::jump(CarbonImmutable::parse('2027-01-31 09:00:00'), 'month', 'UTC')->toIso8601String());
        $this->assertSame('2026-10-12T09:00:00+00:00', PreviewClock::jump(CarbonImmutable::parse('2026-10-05 09:00:00'), 'week', 'UTC')->toIso8601String());
        // The clocks go back in London that night: 09:00 there stays 09:00, so the day is 25 hours.
        $from = CarbonImmutable::parse('2026-10-24 08:00:00', 'UTC');
        $to = PreviewClock::jump($from, 'day', 'Europe/London');
        $this->assertSame('2026-10-25T09:00:00+00:00', $to->toIso8601String());
        $this->assertSame(25 * 3600, $to->getTimestamp() - $from->getTimestamp());
    }

    public function test_due_runs_are_oldest_first_and_kept_to_the_latest()
    {
        $tasks = [
            ['name' => 'daily', 'expression' => '0 8 * * *', 'timezone' => 'UTC'],
            ['name' => 'often', 'expression' => '* * * * *', 'timezone' => 'UTC'],
        ];
        $runs = PreviewClock::due($tasks, CarbonImmutable::parse('2026-10-05 12:00:00'), CarbonImmutable::parse('2026-10-08 12:00:00'), 2, 3);

        // Each task keeps its last 2, and the 3 latest of those are kept in all.
        $this->assertSame([['daily', '2026-10-08 08:00'], ['often', '2026-10-08 11:59'], ['often', '2026-10-08 12:00']], array_map(fn (array $run) => [$run['task'], $run['at']->format('Y-m-d H:i')], $runs['runs']));
        // Each task still counts every time it was due, so the owner can be told what was left out.
        $this->assertSame(['daily' => 3, 'often' => 3 * 1440], $runs['due']);
    }

    public function test_a_timetable_that_cannot_be_read_or_the_clock_file_that_says_nothing_moves_nothing()
    {
        $this->assertSame(['runs' => [], 'due' => []], PreviewClock::due([['name' => 'odd', 'expression' => 'every so often', 'timezone' => 'UTC']], CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-11-05'), 7, 40));
        $this->assertSame(0, PreviewClock::ahead(''));
        $this->assertSame(0, PreviewClock::ahead('{"ahead":-5}'));
        $this->assertSame(3600, PreviewClock::ahead(PreviewClock::file(3600)));
    }
}
