<?php

namespace App\Features;

/**
 * Whether the app can still be prepared for going online (architecture
 * §12). A host runs Laravel's caches when it puts an app online, and an
 * app that boots fine in the preview can still fail them: two routes with
 * one name, a setting that holds a closure, or a screen that uses a
 * component that does not exist. The check runs each cache in the
 * verification copy, prints one line for each that fails, and clears them
 * however it ends, so the checks after it read the live files again. Only
 * the four framework caches run, not `optimize`, because packages add
 * their own steps to that one that may reach a database or the internet.
 * When the check fails, the same lines from the starting commit tell the
 * change's problems from the app's older ones.
 */
class ProductionCaches
{
    /**
     * The check's name, as its result is kept.
     */
    public const CHECK = 'Production caches';

    /**
     * The caches a host prepares, in the order `optimize` runs them.
     */
    public const CACHES = ['config', 'route', 'event', 'view'];

    /**
     * Only a Laravel app has the caches.
     */
    public const NEEDS = 'artisan';

    /**
     * What each kind of problem means for the owner, by the start of the
     * line the script prints for it.
     */
    public const MEANINGS = [
        'route: Unable to prepare route' => 'two pages share a name',
        'config: Your configuration files' => 'one of its settings cannot be prepared ahead of time',
        'view: Unable to locate a class or view for component' => 'a screen uses a part that does not exist',
    ];

    /**
     * Build the check's shell script. Each cache that fails prints its name
     * and the message of what stopped it, without the trace.
     */
    public static function script(): string
    {
        // The message is the first line after the exception's class, or
        // after Laravel's ERROR label, else the first line with text.
        $message = <<<'PHP'
            $lines = array_values(array_filter(array_map('trim', explode("\n", stream_get_contents(STDIN))), 'strlen'));
            $found = $lines[0] ?? 'it stopped without saying why';
            foreach ($lines as $index => $line) {
                if (preg_match('/^[\w\\\\]+(Exception|Error)$/', $line) === 1 && isset($lines[$index + 1])) { $found = $lines[$index + 1]; break; }
                if (preg_match('/^ERROR\s+(.+)$/', $line, $match) === 1) { $found = $match[1]; break; }
            }
            echo $argv[1], ': ', $found, "\n";
            PHP;
        $caches = implode(' ', self::CACHES);

        return implode("\n", [
            "clear() { for c in {$caches}; do php artisan \"\$c:clear\" --no-ansi > /dev/null 2>&1; done; }",
            'trap clear EXIT',
            "trap 'exit 143' INT TERM HUP",
            'clear',
            'code=0',
            "for c in {$caches}; do",
            '  out=$(php artisan "$c:cache" --no-ansi 2>&1) || { code=1; printf %s "$out" | php -r '.escapeshellarg($message).' "$c"; }',
            'done',
            'exit $code',
        ]);
    }

    /**
     * Determine if the check failed on the app as it was before the change,
     * and the change added no problem of its own.
     *
     * @param  array<string, mixed>|null  $result
     */
    public static function failedBefore(?array $result): bool
    {
        return ($result['outcome'] ?? null) === 'failed' && ($result['at_start'] ?? null) === 'failed' && ($result['new_problems'] ?? []) === [];
    }

    /**
     * Say in the owner's words what stops the app going online, from the
     * lines the script printed: each kind once, in the order found.
     *
     * @param  list<string>  $problems
     */
    public static function meaning(array $problems): string
    {
        $meanings = [];

        foreach ($problems as $problem) {
            $meanings[] = collect(self::MEANINGS)->first(fn (string $meaning, string $start) => str_starts_with(trim($problem), $start))
                ?? 'it could not be prepared for going online';
        }

        return implode(', and ', array_values(array_unique($meanings)));
    }
}
