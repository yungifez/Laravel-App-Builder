<?php

namespace App\Features;

use Illuminate\Support\Str;

/**
 * Where the app keeps its saves and its sends, read from the app itself
 * (direction 33, a shape rule). Many Laravel apps do this work in Action
 * or Service classes and keep their controllers thin. Others do it in the
 * controller, and that is fine too. So no style is assumed: the convention
 * is what the rest of the app already does. A change whose new code saves
 * or sends straight from a controller or a Livewire component, in an app
 * that does almost all of that work somewhere else, bypasses the app's
 * own convention.
 *
 * The places come from the recording, as the nearest of the app's own
 * code to each save or send, so only the paths the tests take are seen.
 * It is a strong preference, not a rule: it goes to the reviewer, who
 * judges it against the plan.
 */
class AppConventions
{
    /**
     * New code that does its work outside the place the app keeps it in.
     */
    public const BYPASSED = 'convention_bypassed';

    /**
     * The parts of an app a request enters through. Work done straight in
     * them is what a convention keeps elsewhere.
     */
    protected const ENTRIES = ['controller', 'component'];

    /**
     * The most findings kept, so one change cannot fill the row.
     */
    protected const KEPT = 20;

    /**
     * Find the app's conventions, and the new code that bypasses them. A
     * convention is a role that holds at least "share" of the places the
     * rest of the app saves (or sends) from, and at least "least" of them.
     * Null when the recording shows no convention.
     *
     * @param  list<array{test: string|null, method: string, route: string|null, effects: list<array{kind: string, sql?: string, at?: string|null, frames?: list<string>}>}>  $requests  From AppTraces::parse()
     * @return array{conventions: array<string, array{role: string, places: int, of: int}>, findings: list<array{work: string, role: string, route: string, at: string, in: string, test: string|null}>}|null
     */
    public static function measure(array $requests, ?string $patch, int $least = 5, float $share = 0.8): ?array
    {
        $added = AppTraces::addedLines($patch);
        $places = [];
        $new = [];

        foreach ($requests as $request) {
            foreach ($request['effects'] as $effect) {
                $work = self::work($effect);
                $at = $effect['at'] ?? null;
                $in = $effect['frames'][0] ?? null;

                if ($work === null || ! is_string($at) || $in === null) {
                    continue;
                }

                $role = self::role($in);

                if (AppTraces::onAddedLine($at, $added)) {
                    $new[$work.'|'.$in] ??= ['work' => $work, 'role' => $role, 'route' => $request['method'].' '.($request['route'] ?? '?'), 'at' => $at, 'in' => $in, 'test' => $request['test']];
                } else {
                    // Counted by place, so one busy loop is one place.
                    $places[$work][$at] = $role;
                }
            }
        }

        $conventions = [];

        foreach ($places as $work => $roles) {
            $counts = array_count_values(array_filter($roles, fn (?string $role) => $role !== null));
            arsort($counts);
            $role = (string) array_key_first($counts);
            $count = $counts[$role] ?? 0;

            if ($count >= $least && $count / count($roles) >= $share && ! in_array($role, self::ENTRIES, true)) {
                $conventions[$work] = ['role' => $role, 'places' => $count, 'of' => count($roles)];
            }
        }

        if ($conventions === []) {
            return null;
        }

        $findings = array_values(array_filter($new, fn (array $finding) => isset($conventions[$finding['work']]) && in_array($finding['role'], self::ENTRIES, true)));

        return [
            'conventions' => $conventions,
            'findings' => array_slice(array_map(fn (array $finding) => [...$finding, 'role' => (string) $finding['role']], $findings), 0, self::KEPT),
        ];
    }

    /**
     * Name the role of a class by the folder Laravel apps keep it in, at
     * any depth, so "App\Billing\Actions\Charge" is an action too.
     */
    public static function role(string $frame): ?string
    {
        $class = '\\'.Str::before($frame, '::');

        foreach ([
            '\\Http\\Controllers\\' => 'controller',
            '\\Livewire\\' => 'component',
            '\\Actions\\' => 'action',
            '\\Services\\' => 'service',
            '\\Jobs\\' => 'job',
            '\\Listeners\\' => 'listener',
            '\\Observers\\' => 'observer',
            '\\Models\\' => 'model',
        ] as $folder => $role) {
            if (str_contains($class, $folder)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Say what the reviewer reads about one finding.
     *
     * @param  array{work: string, role: string, route: string, at: string, in: string}  $finding
     * @param  array<string, array{role: string, places: int, of: int}>  $conventions
     */
    public static function describe(array $finding, array $conventions): string
    {
        $convention = $conventions[$finding['work']];

        return sprintf('%s: a %s at %s in %s. Of the %d %ss seen in the rest of the app, %d are in %s classes.', $finding['route'], $finding['work'], $finding['at'], $finding['in'], $convention['of'], $finding['work'], $convention['places'], ucfirst($convention['role']));
    }

    /**
     * Get the kind of work a recorded thing is: a save, a send, or neither.
     *
     * @param  array{kind: string, sql?: string}  $effect
     */
    protected static function work(array $effect): ?string
    {
        return match (true) {
            AppTraces::writes($effect) => 'save',
            in_array($effect['kind'], ['mail', 'notification', 'http', 'job'], true) => 'send',
            default => null,
        };
    }
}
