<?php

namespace App\Features;

/**
 * How much work the app does per request in each of its areas, and
 * whether a change made that grow (direction 33, a drift measure). One
 * more query in one change is nothing. Twenty changes that each add one
 * make a slow page nobody chose. So each area keeps a ceiling, set when a
 * change is kept: it moves down when the area does less, and up only when
 * the owner said they want the change that made it grow.
 *
 * A request's work in an area is what the area's own files did in it: its
 * queries, and what it queued and sent. The measure comes from the
 * recording, so it depends on the tests the app has. That makes it a
 * note, never a send back, except in a part the owner asked to be extra
 * careful with, and then only when the work grew far past the ceiling.
 */
class AppDrift
{
    /**
     * The work per request in an area grew past its ceiling.
     */
    public const GREW = 'drift_grew';

    /**
     * What a trace marks but the app's code did not do: the edges of a
     * transaction, the answer, and work put off until after it.
     */
    protected const MARKS = ['begin', 'commit', 'rollback', 'answer', 'later'];

    /**
     * Count the work per request in each area. A request counts for an
     * area when the area's own files did something in it. An area with
     * fewer than "least" such requests is left out: a few requests say
     * nothing about the area.
     *
     * @param  list<array{effects: list<array{kind: string, at?: string|null, again?: bool}>, cut: bool}>  $requests  From AppTraces::parse()
     * @param  callable(string): list<string>  $areas  The keys of the areas a file of the app belongs to
     * @return array<string, array{requests: int, effects: int, per: float}>
     */
    public static function measure(array $requests, callable $areas, int $least = 3): array
    {
        $totals = [];

        foreach ($requests as $request) {
            if ($request['cut']) {
                continue;
            }

            $counted = [];

            foreach ($request['effects'] as $effect) {
                $at = $effect['at'] ?? null;

                if (! is_string($at) || in_array($effect['kind'], self::MARKS, true) || ($effect['again'] ?? false)) {
                    continue;
                }

                foreach ($areas((string) preg_replace('/:\d+$/', '', $at)) as $area) {
                    $counted[$area] = ($counted[$area] ?? 0) + 1;
                }
            }

            foreach ($counted as $area => $effects) {
                $totals[$area]['requests'] = ($totals[$area]['requests'] ?? 0) + 1;
                $totals[$area]['effects'] = ($totals[$area]['effects'] ?? 0) + $effects;
            }
        }

        $measured = [];

        foreach ($totals as $area => $total) {
            if ($total['requests'] >= $least) {
                $measured[(string) $area] = [...$total, 'per' => round($total['effects'] / $total['requests'], 1)];
            }
        }

        ksort($measured);

        return $measured;
    }

    /**
     * Find the areas whose work per request grew more than "tolerance"
     * past their ceiling. "far" says it grew more than "strict" past it. An
     * area with no ceiling yet is new to the measure, so it is not found.
     *
     * @param  array<string, array{requests: int, effects: int, per: float|int}>  $measured  From measure()
     * @param  array<string, float|int>  $ceilings
     * @return list<array{area: string, per: float|int, ceiling: float, far: bool}>
     */
    public static function grown(array $measured, array $ceilings, float $tolerance, float $strict): array
    {
        $found = [];

        foreach ($measured as $area => $measure) {
            $ceiling = $ceilings[$area] ?? null;

            if ($ceiling !== null && $measure['per'] > $ceiling * (1 + $tolerance)) {
                $found[] = ['area' => $area, 'per' => $measure['per'], 'ceiling' => (float) $ceiling, 'far' => $measure['per'] > $ceiling * (1 + $strict)];
            }
        }

        return $found;
    }

    /**
     * Set the ceilings after a change is kept. An area new to the measure
     * gets one, and one that does less moves its ceiling down. One that
     * grew moves it up only when the owner said they want that growth;
     * otherwise each later change is held to the old ceiling. "slack" keeps
     * a little room, so noise in the tests does not lock an area.
     *
     * @param  array<string, array{requests: int, effects: int, per: float|int}>  $measured  From measure()
     * @param  array<string, float|int>  $ceilings
     * @param  list<string>  $wanted  The areas whose growth the owner wants
     * @return array<string, float>
     */
    public static function ratchet(array $measured, array $ceilings, float $slack, array $wanted = []): array
    {
        $next = array_map(fn (float|int $ceiling) => (float) $ceiling, $ceilings);

        foreach ($measured as $area => $measure) {
            $room = round($measure['per'] * (1 + $slack), 1);

            if (! isset($next[$area]) || $room < $next[$area] || in_array($area, $wanted, true)) {
                $next[$area] = $room;
            }
        }

        ksort($next);

        return $next;
    }

    /**
     * Name a finding by what it is: the area that grew.
     *
     * @param  array{area: string}  $finding
     */
    public static function identity(array $finding): string
    {
        return self::GREW.'|'.$finding['area'];
    }

    /**
     * Say what a finding is for the reviewer, with the area's name.
     *
     * @param  array{area: string, per: float|int, ceiling: float|int}  $finding
     */
    public static function describe(array $finding, string $name): string
    {
        return __(':name: :per things per request, up from at most :ceiling when the app was last kept', [
            'name' => $name,
            'per' => $finding['per'],
            'ceiling' => $finding['ceiling'],
        ]);
    }

    /**
     * Say what a finding is, and how to fix it, for the coder, in a part
     * the owner asked to be extra careful with.
     *
     * @param  array{area: string, per: float|int, ceiling: float|int}  $finding
     */
    public static function finding(array $finding, string $name): string
    {
        return __(':name now does :per things per request while the tests run: queries, and what it queues and sends. It did at most :ceiling when the app was last kept. The owner asked to be extra careful with this part of the app, so it holds the change back. Load what a request needs in fewer queries, for example with eager loading, and do not repeat work the request already did.', [
            'name' => $name,
            'per' => $finding['per'],
            'ceiling' => $finding['ceiling'],
        ]);
    }
}
