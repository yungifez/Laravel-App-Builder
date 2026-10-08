<?php

namespace App\Previews;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use InvalidArgumentException;
use Throwable;

/**
 * Moving the app on show ahead in time. The trace recorder inside the app
 * reads "clock.json" in its folder as each request and command starts, and
 * runs the app's clock that many seconds ahead of the real one. Skipping
 * time also runs what the app's schedule would have run in it.
 */
class PreviewClock
{
    /**
     * How far the owner can jump at once.
     */
    public const JUMPS = ['day', 'week', 'month'];

    /**
     * Get the moment one jump after "from", by the calendar: a month from
     * 31 January is the end of February, and a day keeps its clock time
     * when the clocks change.
     */
    public static function jump(CarbonImmutable $from, string $jump, string $timezone): CarbonImmutable
    {
        $local = $from->setTimezone($timezone);

        $to = match ($jump) {
            'day' => $local->addDay(),
            'week' => $local->addWeek(),
            'month' => $local->addMonthNoOverflow(),
            default => throw new InvalidArgumentException("No jump [{$jump}]."),
        };

        return $to->utc();
    }

    /**
     * Read how many seconds ahead the clock file says the app is.
     */
    public static function ahead(string $file): int
    {
        $clock = json_decode($file, true);

        return is_array($clock) && is_int($clock['ahead'] ?? null) ? max(0, $clock['ahead']) : 0;
    }

    /**
     * The clock file for so many seconds ahead.
     */
    public static function file(int $ahead): string
    {
        return (string) json_encode(['ahead' => max(0, $ahead)]);
    }

    /**
     * Get each time a task would have run after "from", up to "to", oldest
     * first. A task that runs often keeps only its last "each" times, so a
     * month of a task that runs every minute stays a few runs; at most
     * "most" runs are kept in all, the latest ones.
     *
     * @param  list<array{name: string, expression: string, timezone: string}>  $tasks
     * @return list<array{task: string, at: CarbonImmutable}>
     */
    public static function due(array $tasks, CarbonImmutable $from, CarbonImmutable $to, int $each, int $most): array
    {
        $runs = [];

        foreach ($tasks as $task) {
            try {
                $cron = new CronExpression($task['expression']);
            } catch (Throwable) {
                continue;
            }

            $times = [];
            $at = $from;

            // A timetable that never comes round again stops the search.
            while (($next = rescue(fn () => CarbonImmutable::instance($cron->getNextRunDate($at, 0, false, $task['timezone'])), null, report: false)) !== null && $next->lessThanOrEqualTo($to)) {
                $times[] = $next;
                $times = array_slice($times, -$each);
                $at = $next;
            }

            foreach ($times as $time) {
                $runs[] = ['task' => $task['name'], 'at' => $time->utc()];
            }
        }

        usort($runs, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return array_slice($runs, -$most);
    }

    /**
     * Run a command of the app with the recorder loaded, the way the app on
     * show runs: its clock, and what it does is recorded. PHP loads the
     * recorder from the ini file in "ini", so each command the schedule
     * starts loads it too. $1 is the recorder's folder.
     *
     * @return list<string>
     */
    public static function command(string $directory, string ...$command): array
    {
        return ['sh', '-c', 'export TRACE_RECORDER_DIR="$PWD/$1" PHP_INI_SCAN_DIR=":$PWD/$1/ini"; shift; exec "$@"', 'sh', $directory, ...array_values($command)];
    }

    /**
     * A script that moves the sessions of the app on show ahead by its
     * first argument in seconds, so whoever is signed in stays signed in
     * once the app's clock has moved. Sessions kept in the database or in
     * files go by the app's clock; the others go by the real one.
     */
    public static function sessions(): string
    {
        return <<<'PHP'
        <?php

        $seconds = (int) ($argv[1] ?? 0);
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $config = $app->make('config');

        if ($config->get('session.driver') === 'database') {
            $app->make('db')->connection($config->get('session.connection'))
                ->table($config->get('session.table', 'sessions'))
                ->update(['last_activity' => $app->make('db')->raw('last_activity + '.$seconds)]);
        }

        if ($config->get('session.driver') === 'file') {
            foreach (glob(rtrim((string) $config->get('session.files'), '/').'/*') ?: [] as $file) {
                @touch($file, filemtime($file) + $seconds);
            }
        }
        PHP;
    }
}
