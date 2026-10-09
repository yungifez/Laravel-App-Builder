<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * Which parts of the app call into which others, and the calls a change
 * adds (direction 33, a drift measure: rising coupling between domains).
 * The recording lists the app's own code on the way to each thing a
 * request did. Where one link of that chain is in one area of the notes
 * and the next in another, the first area depends on the second.
 *
 * What the app already depends on is read from the chains that pass
 * through no file the change touched. A dependency seen only in chains
 * through the change's files is new. Only the paths the tests take are
 * seen, so this is a note for the reviewer, never a send back.
 */
class AppCoupling
{
    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 20;

    /**
     * Find the dependencies between areas that only the change's code
     * makes. Null when the recording shows no call between areas at all.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, effects: list<array{kind: string, frames?: list<string>}>}>  $requests  From AppTraces::parse()
     * @param  callable(string): list<string>  $areas  The names of the areas a file of the app belongs to
     * @return array{known: int, findings: list<array{from: string, to: string, caller: string, callee: string, route: string, test: string|null}>}|null
     */
    public static function measure(array $requests, ?string $patch, callable $areas): ?array
    {
        $touched = array_keys(AppTraces::addedLines($patch));
        $known = [];
        $new = [];

        foreach ($requests as $request) {
            foreach ($request['effects'] as $effect) {
                $frames = $effect['frames'] ?? [];
                $changed = array_any($frames, fn (string $frame) => in_array(self::path($frame), $touched, true));

                // Nearest first, so each frame was called by the next.
                for ($i = 0; $i < count($frames) - 1; $i++) {
                    $callee = $areas(self::path($frames[$i]));
                    $caller = $areas(self::path($frames[$i + 1]));

                    foreach (array_diff($caller, $callee) as $from) {
                        foreach (array_diff($callee, $caller) as $to) {
                            if (! $changed) {
                                $known["{$from}|{$to}"] = true;
                            } else {
                                $new["{$from}|{$to}"] ??= ['from' => $from, 'to' => $to, 'caller' => $frames[$i + 1], 'callee' => $frames[$i], 'route' => $request['method'].' '.($request['route'] ?? '?'), 'test' => $request['test']];
                            }
                        }
                    }
                }
            }
        }

        if ($known === [] && $new === []) {
            return null;
        }

        ksort($new);

        return [
            'known' => count($known),
            'findings' => array_slice(array_values(array_diff_key($new, $known)), 0, self::KEPT),
        ];
    }

    /**
     * Say what the reviewer reads about one finding.
     *
     * @param  array{from: string, to: string, caller: string, callee: string, route: string}  $finding
     */
    public static function describe(array $finding): string
    {
        return sprintf('%s now calls into %s: %s calls %s (%s)', $finding['from'], $finding['to'], $finding['caller'], $finding['callee'], $finding['route']);
    }

    /**
     * Get the file a class of the app lives in, as Laravel's own autoload
     * maps it: "App\Billing\Charge" is in app/Billing/Charge.php.
     */
    protected static function path(string $frame): string
    {
        $class = Str::before($frame, '::');

        return str_starts_with($class, 'App\\')
            ? 'app/'.str_replace('\\', '/', Str::after($class, 'App\\')).'.php'
            : '';
    }
}
