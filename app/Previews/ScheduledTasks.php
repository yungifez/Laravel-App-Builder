<?php

namespace App\Previews;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The tasks an app runs on its own, read from what `schedule:list --json`
 * prints, each with when it runs in plain words.
 */
class ScheduledTasks
{
    /**
     * Read the tasks, in the order the app lists them.
     *
     * @return list<array{name: string, words: string, when: string, next: string|null, expression: string, command: string, timezone: string, repeat: int|null}>
     */
    public static function in(string $output): array
    {
        // Notices a package prints come before the JSON line.
        $line = collect(explode("\n", $output))->last(fn (string $line) => str_starts_with(trim($line), '['));
        $tasks = json_decode((string) $line, true);

        return array_values(collect(is_array($tasks) ? $tasks : [])
            ->filter(fn ($task) => is_array($task) && is_string($task['command'] ?? null) && is_string($task['expression'] ?? null))
            ->map(fn (array $task) => [
                'name' => self::name((string) $task['command']),
                'words' => self::words((string) $task['command'], is_string($task['description'] ?? null) ? $task['description'] : null),
                'when' => self::when((string) $task['expression'], is_int($task['repeat_seconds'] ?? null) ? $task['repeat_seconds'] : null, is_string($task['timezone'] ?? null) ? $task['timezone'] : 'UTC'),
                'next' => is_string($task['next_due_date'] ?? null) ? rescue(fn () => CarbonImmutable::parse($task['next_due_date'])->toIso8601String(), null, report: false) : null,
                'expression' => (string) $task['expression'],
                'command' => (string) $task['command'],
                'timezone' => is_string($task['timezone'] ?? null) ? $task['timezone'] : 'UTC',
                'repeat' => is_int($task['repeat_seconds'] ?? null) ? $task['repeat_seconds'] : null,
            ])
            // A task listed once for each of its times is one task here.
            ->unique('name')
            ->all());
    }

    /**
     * The name `schedule:test --name` knows the task by: an Artisan command
     * without "php artisan", or "Closure" for a task written as a function.
     */
    protected static function name(string $command): string
    {
        return match (true) {
            str_starts_with($command, 'php artisan ') => Str::after($command, 'php artisan '),
            str_starts_with($command, 'Closure at: ') => 'Closure',
            default => $command,
        };
    }

    /**
     * What the task does, in the app's own words when it gave some.
     */
    public static function words(string $command, ?string $description = null): string
    {
        // A queued job is listed by its class, as its own description too.
        $class = fn (string $text) => preg_match('/^\\\\?[A-Za-z_]\w*(\\\\[A-Za-z_]\w*)+$/', $text) === 1;

        if (filled($description) && ! $class($description)) {
            return Str::ucfirst($description);
        }

        if ($class($command)) {
            return Str::ucfirst(Str::lower(Str::headline(class_basename($command))));
        }

        if (str_starts_with($command, 'Closure at: ')) {
            return 'A task without a name';
        }

        $name = str_starts_with($command, 'php artisan ') ? Str::after($command, 'php artisan ') : $command;

        return Str::of(strtok($name, ' ') ?: $name)->replace([':', '-', '_'], ' ')->lower()->ucfirst()->toString();
    }

    /**
     * When the task runs, in plain words, for the common timetables.
     */
    protected static function when(string $expression, ?int $repeatSeconds, string $timezone): string
    {
        if ($repeatSeconds !== null) {
            return "Every {$repeatSeconds} seconds";
        }

        $parts = preg_split('/\s+/', trim($expression)) ?: [];

        if (count($parts) !== 5) {
            return 'On its own timetable';
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;
        // A set time is the app's clock, which may not be the owner's.
        $at = ctype_digit($minute) && ctype_digit($hour) ? sprintf('%02d:%02d %s', $hour, $minute, $timezone) : null;
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        return match (true) {
            $expression === '* * * * *' => 'Every minute',
            preg_match('/^\*\/(\d+)$/', $minute, $every) === 1 && "{$hour}{$day}{$month}{$weekday}" === '****' => "Every {$every[1]} minutes",
            ctype_digit($minute) && "{$hour}{$day}{$month}{$weekday}" === '****' => $minute === '0' ? 'Every hour' : sprintf('Every hour at :%02d', $minute),
            ctype_digit($minute) && preg_match('/^\*\/(\d+)$/', $hour, $every) === 1 && "{$day}{$month}{$weekday}" === '***' => "Every {$every[1]} hours",
            $at !== null && "{$day}{$month}{$weekday}" === '***' => "Every day at {$at}",
            $at !== null && "{$day}{$month}" === '**' && $weekday === '1-5' => "Every weekday at {$at}",
            $at !== null && "{$day}{$month}" === '**' && ctype_digit($weekday) && (int) $weekday <= 7 => "Every {$days[(int) $weekday]} at {$at}",
            $at !== null && ctype_digit($day) && "{$month}{$weekday}" === '**' => 'Every month on day '.$day." at {$at}",
            default => 'On its own timetable',
        };
    }
}
