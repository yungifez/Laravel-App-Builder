<?php

namespace App\Previews;

use App\Actions\Previews\ReadPreviewHappenings;
use Carbon\CarbonImmutable;

/**
 * The errors an app wrote to its log while the owner tried it. The same
 * fault met again is counted, not listed again, so an app failing on every
 * page stays readable.
 */
class LoggedProblems
{
    /**
     * The levels that mean something went wrong. Warnings and notes are
     * left out: they rarely mean the owner saw anything.
     */
    protected const LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /**
     * The name the app's log gives a request made while the owner had
     * something down on purpose. The recorder in the app on show writes it.
     */
    protected const DURING = 'simulated_outage';

    /**
     * Find the kinds of problem in a log, the most recent first.
     *
     * @return list<array{id: string, words: string, class: string|null, message: string, place: string|null, trace: list<string>, during: string|null, count: int, first_at: string|null, last_at: string|null}>
     */
    public static function in(string $log, int $limit = 30): array
    {
        /** @var array<string, array{problem: array{class: string|null, message: string, place: string|null, trace: list<string>, during: string|null}, count: int, first_at: string|null, last_at: string|null}> $kinds */
        $kinds = [];

        // The log is in order, so the kind met last is the newest, and the
        // newest message and path to it say most about it now.
        foreach (LogEntries::in($log) as $entry) {
            if (! in_array($entry['level'], self::LEVELS, true)) {
                continue;
            }

            $problem = self::read($entry['message']);
            $at = rescue(fn () => CarbonImmutable::parse($entry['time'])->toIso8601String(), null, report: false);
            // The same error with something down on purpose is a different
            // problem: the question there is how the app copes without it.
            $id = sha1(($problem['class'] ?? '').'|'.preg_replace('/\d+/', '#', $problem['message']).'|'.$problem['place'].'|'.$problem['during']);
            $kind = $kinds[$id] ?? ['problem' => $problem, 'count' => 0, 'first_at' => $at, 'last_at' => $at];

            unset($kinds[$id]);
            $kinds[$id] = ['problem' => $problem, 'count' => $kind['count'] + 1, 'first_at' => $kind['first_at'], 'last_at' => $at];
        }

        $found = [];

        foreach (array_slice(array_reverse($kinds, true), 0, $limit, true) as $id => $kind) {
            $found[] = [
                'id' => (string) $id,
                'words' => self::words($kind['problem']['class'], $kind['problem']['message'], $kind['problem']['during']),
                ...$kind['problem'],
                'count' => $kind['count'],
                'first_at' => $kind['first_at'],
                'last_at' => $kind['last_at'],
            ];
        }

        return $found;
    }

    /**
     * Read one entry: what failed, where, and the app's own code on the way
     * there. An exception is written with its class, place and trace; a
     * problem the app wrote itself has only a message.
     *
     * @return array{class: string|null, message: string, place: string|null, trace: list<string>, during: string|null}
     */
    protected static function read(string $entry): array
    {
        // What the owner made fail on purpose while this happened, if anything.
        $during = preg_match('/"'.self::DURING.'":"('.implode('|', array_keys(ReadPreviewHappenings::FAULTS)).'):/', $entry, $outage) === 1 ? $outage[1] : null;

        // Paths are shown from the app's folder, the one that holds vendor/.
        $root = preg_match('#(/[^\s:(]*?)/vendor/#', $entry, $match) === 1 ? $match[1].'/' : null;
        $relative = fn (string $path) => $root !== null && str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;

        // The message runs to the last " at " before the first trace.
        if (preg_match('/\[object\] \((.+?)\(code: [^)]*\): ((?:(?!\n\[stacktrace\]).)*) at ([^\n]+?):(\d+)\)\n\[stacktrace\]\n(.*)/s', $entry, $match) === 1) {
            preg_match_all('/^#\d+ (\S+?)\((\d+)\):/m', $match[5], $frames, PREG_SET_ORDER);

            $place = $relative($match[3]).':'.$match[4];
            // What is not under the app's folder, such as a tool the builder
            // loads into the app on show, is not the app's own code.
            $outside = fn (string $at) => $root !== null && str_starts_with($at, '/');
            $trace = array_values(collect($frames)
                ->map(fn (array $frame) => $relative($frame[1]).':'.$frame[2])
                ->reject(fn (string $at) => $at === $place || str_starts_with($at, 'vendor/') || str_contains($at, '/vendor/') || $outside($at))
                ->unique()
                ->take(6)
                ->all());

            // A problem raised outside the app's folder is placed at the
            // app's own code that led there.
            if ($outside($place)) {
                $place = array_shift($trace);
            }

            $trace = array_slice($trace, 0, 5);

            return [
                'class' => str_replace('\\\\', '\\', $match[1]),
                'message' => stripcslashes(trim($match[2])),
                'place' => $place,
                'trace' => $trace,
                'during' => $during,
            ];
        }

        // A message the app wrote, without the details after it: its own,
        // then what the log adds to each line.
        $message = preg_replace('/ (?:\{.*\}|\[\])(?: (?:\{.*\}|\[\]))?$/s', '', $entry) ?? $entry;

        return ['class' => null, 'message' => trim($message), 'place' => null, 'trace' => [], 'during' => $during];
    }

    /**
     * Say what went wrong in the owner's words. The details are kept for
     * a developer and for the fix.
     */
    protected static function words(?string $class, string $message, ?string $during = null): string
    {
        $class = (string) $class;

        return match (true) {
            // The owner made this fail; what they learn is that the app did not cope.
            $during !== null => __('Your app stopped with an error while :what.', ['what' => match ($during) {
                'mail' => __('email was down'),
                'http' => __('outside services did not answer'),
                'file' => __('storage was full'),
                'cache' => __('the cache was down'),
                default => __('notices could not go out'),
            }]),
            str_contains($class, 'QueryException'), str_contains($class, 'PDOException') => 'Saving or reading data failed.',
            str_contains($class, 'ModelNotFoundException') => 'Something the app looked for was not there.',
            str_contains($class, 'ViewException'), str_contains($message, 'View [') => 'A page could not be drawn.',
            str_contains($class, 'TransportException'), str_contains($class, 'MailException') => 'An email could not be sent.',
            str_contains($class, 'ConnectionException'), str_contains($class, 'RequestException') => 'The app could not reach another service.',
            str_contains($class, 'MissingAppKeyException'), str_contains($class, 'DecryptException') => 'The app is missing a setting it needs.',
            str_contains($message, 'Class "') && str_contains($message, 'not found'),
            str_contains($message, 'Call to undefined') => 'A part of the app is missing.',
            str_contains($message, 'Undefined variable'),
            str_contains($message, 'Undefined array key'),
            str_contains($message, 'on null') => 'The app used something that was not there.',
            str_contains($class, 'TypeError') => 'The app got a different kind of value than it expected.',
            str_contains($class, 'ValueError'), str_contains($class, 'DivisionByZeroError') => 'The app could not work something out.',
            $class === '' => 'Your app noted a problem.',
            default => 'Something went wrong in your app.',
        };
    }
}
