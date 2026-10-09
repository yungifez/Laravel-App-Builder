<?php

namespace App\VisualEditing;

use InvalidArgumentException;

/**
 * Reads and writes how a part moves, as Tailwind utility classes: how it
 * appears (an entrance, with Tailwind v4's "starting:" variant), how fast,
 * after what wait, what it does while the pointer is on it, and whether it
 * keeps moving. Movement is written under "motion-safe:", so people who
 * ask their device for less motion see the part fade at most.
 *
 * The owner picks from a few ready-made choices. A part that moves some
 * other way (its own keyframes, a different motion per screen size) is
 * described as far as it can be, and the rest is left to the coding agent.
 */
class MotionClasses
{
    public const ENTRANCES = ['none', 'fade', 'rise', 'slide', 'zoom'];

    public const SPEEDS = ['quick', 'normal', 'slow'];

    public const WAITS = ['none', 'short', 'long'];

    public const HOVERS = ['none', 'lift', 'grow'];

    public const LOOPS = ['none', 'pulse', 'bounce', 'spin'];

    /**
     * The classes each choice writes.
     *
     * @var array<string, array<string, list<string>>>
     */
    protected const WRITES = [
        'entrance' => [
            'fade' => ['starting:opacity-0'],
            'rise' => ['starting:opacity-0', 'motion-safe:starting:translate-y-4'],
            'slide' => ['starting:opacity-0', 'motion-safe:starting:-translate-x-6'],
            'zoom' => ['starting:opacity-0', 'motion-safe:starting:scale-95'],
        ],
        'speed' => ['quick' => ['duration-300'], 'normal' => ['duration-500'], 'slow' => ['duration-750']],
        'wait' => ['short' => ['delay-150'], 'long' => ['delay-300']],
        'hover' => [
            'lift' => ['motion-safe:hover:-translate-y-0.5', 'hover:shadow-md'],
            'grow' => ['motion-safe:hover:scale-105'],
        ],
        'loop' => ['pulse' => ['motion-safe:animate-pulse'], 'bounce' => ['motion-safe:animate-bounce'], 'spin' => ['motion-safe:animate-spin']],
    ];

    /**
     * The ready-made choice that suits a kind of part, so the panel can
     * offer it first. A part not listed gets no suggestion.
     *
     * @return array{entrance?: string, hover?: string}
     */
    public static function suggested(?string $tag): array
    {
        return match ($tag) {
            'button', 'a' => ['hover' => 'lift'],
            'h1', 'h2' => ['entrance' => 'rise'],
            'img', 'picture', 'video', 'svg' => ['entrance' => 'fade'],
            'section', 'article', 'li' => ['entrance' => 'rise', 'hover' => 'lift'],
            default => [],
        };
    }

    /**
     * Read how the part moves: the closest ready-made choice for each
     * aspect, whether anything is beyond them, and the whole in words.
     *
     * @return array{entrance: string, speed: string, wait: string, hover: string, loop: string, moves: bool, custom: bool, words: string|null}
     */
    public static function read(string $classes): array
    {
        $tokens = self::tokens($classes);
        $starting = [];
        $custom = false;
        $duration = null;
        $delay = null;
        $hover = 'none';
        $loop = 'none';

        foreach ($tokens as $token) {
            $bare = (string) preg_replace('/^motion-(safe|reduce):/', '', $token);

            if (str_starts_with($bare, 'starting:')) {
                $starting[] = substr($bare, 9);
            } elseif (preg_match('/^duration-(\d+)$/', $token, $match)) {
                $duration = (int) $match[1];
            } elseif (preg_match('/^delay-(\d+)$/', $token, $match)) {
                $delay = (int) $match[1];
            } elseif (preg_match('/^hover:-?translate-y-/', $bare)) {
                $hover = 'lift';
            } elseif (preg_match('/^hover:scale-/', $bare)) {
                $hover = 'grow';
            } elseif (preg_match('/^animate-(pulse|bounce|spin)$/', $bare, $match)) {
                $loop = $match[1];
            } elseif (preg_match('/^animate-(?!none$)/', $bare) || preg_match('/^[a-z0-9-]+:(.+:)?(animate|transition|duration|delay|starting)/', $bare)) {
                // Its own animation, or motion for one screen size only.
                $custom = true;
            }
        }

        $entrance = self::entrance($starting);

        if ($entrance === 'custom') {
            $custom = true;
        }

        $speed = match (true) {
            $duration === null => 'normal',
            $duration <= 300 => 'quick',
            $duration <= 500 => 'normal',
            default => 'slow',
        };
        $wait = match (true) {
            $delay === null || $delay === 0 => 'none',
            $delay <= 150 => 'short',
            default => 'long',
        };
        $moves = $starting !== [] || $hover !== 'none' || $loop !== 'none' || $custom;

        return [
            'entrance' => $entrance === 'custom' ? 'none' : $entrance,
            'speed' => $speed,
            'wait' => $wait,
            'hover' => $hover,
            'loop' => $loop,
            'moves' => $moves,
            'custom' => $custom,
            'words' => $moves ? self::words($starting, $duration, $delay, $hover, $loop, $custom) : null,
        ];
    }

    /**
     * Write the chosen motion into the classes, in place of the motion they
     * had. Classes that are not motion stay as they are, and so does a
     * colour change's own easing ("transition-colors") when nothing else
     * moves.
     *
     * @param  array{entrance: string, speed: string, wait: string, hover: string, loop: string}  $motion
     *
     * @throws InvalidArgumentException when a choice is not one of the ready-made ones.
     */
    public static function write(string $classes, array $motion): string
    {
        foreach (['entrance' => self::ENTRANCES, 'speed' => self::SPEEDS, 'wait' => self::WAITS, 'hover' => self::HOVERS, 'loop' => self::LOOPS] as $aspect => $choices) {
            if (! in_array($motion[$aspect], $choices, true)) {
                throw new InvalidArgumentException("Unknown {$aspect}.");
            }
        }

        $transitions = $motion['entrance'] !== 'none' || $motion['hover'] !== 'none';
        $tokens = self::tokens($classes);
        $keepsColors = ! $transitions && in_array('transition-colors', $tokens, true);

        $kept = array_values(array_filter($tokens, function (string $token) use ($keepsColors) {
            $bare = (string) preg_replace('/^motion-(safe|reduce):/', '', $token);

            return ! (
                str_starts_with($bare, 'starting:')
                || preg_match('/^transition(-(all|transform|opacity))?$/', $token)
                || ($token === 'transition-colors' && ! $keepsColors)
                || (preg_match('/^(duration|ease)-/', $token) && ! $keepsColors)
                || preg_match('/^delay-/', $token)
                || preg_match('/^hover:(-?translate-y-|scale-)/', $bare)
                || preg_match('/^animate-/', $bare)
                || $token === 'hover:shadow-md'
            );
        }));

        $added = [];

        if ($transitions) {
            $added = ['transition-all', ...self::WRITES['speed'][$motion['speed']], 'ease-out', ...(self::WRITES['wait'][$motion['wait']] ?? [])];
        }

        foreach (['entrance', 'hover', 'loop'] as $aspect) {
            $added = [...$added, ...(self::WRITES[$aspect][$motion[$aspect]] ?? [])];
        }

        return implode(' ', [...$kept, ...$added]);
    }

    /**
     * @return list<string>
     */
    protected static function tokens(string $classes): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($classes)) ?: [], fn (string $token) => $token !== ''));
    }

    /**
     * Name how the part appears from what it starts as.
     *
     * @param  list<string>  $starting
     */
    protected static function entrance(array $starting): string
    {
        if ($starting === []) {
            return 'none';
        }

        $rest = array_values(array_filter($starting, fn (string $token) => $token !== 'opacity-0'));

        return match (true) {
            $rest === [] => 'fade',
            count($rest) === 1 && (bool) preg_match('/^translate-y-/', $rest[0]) => 'rise',
            count($rest) === 1 && (bool) preg_match('/^-?translate-x-/', $rest[0]) => 'slide',
            count($rest) === 1 && (bool) preg_match('/^scale-/', $rest[0]) => 'zoom',
            default => 'custom',
        };
    }

    /**
     * Say how the part moves, in a sentence.
     *
     * @param  list<string>  $starting
     */
    protected static function words(array $starting, ?int $duration, ?int $delay, string $hover, string $loop, bool $custom): string
    {
        $sentences = [];

        if ($starting !== []) {
            $how = match (true) {
                (bool) preg_grep('/^-translate-x-/', $starting) => __('Slides in from the left'),
                (bool) preg_grep('/^translate-x-/', $starting) => __('Slides in from the right'),
                (bool) preg_grep('/^translate-y-/', $starting) => __('Rises into place'),
                (bool) preg_grep('/^-translate-y-/', $starting) => __('Drops into place'),
                (bool) preg_grep('/^scale-/', $starting) => __('Grows into place'),
                default => __('Fades in'),
            };
            $pace = match (true) {
                $duration === null => '',
                $duration <= 300 => __(', quickly'),
                $duration > 500 => __(', slowly'),
                default => '',
            };
            $after = match (true) {
                $delay === null || $delay === 0 => '',
                $delay <= 150 => __(', after a short wait'),
                default => __(', after a wait'),
            };
            $sentences[] = $how.$pace.$after.'.';
        }

        if ($hover !== 'none') {
            $sentences[] = $hover === 'lift' ? __('Lifts when the pointer is on it.') : __('Grows when the pointer is on it.');
        }

        if ($loop !== 'none') {
            $sentences[] = match ($loop) {
                'pulse' => __('Keeps pulsing.'),
                'bounce' => __('Keeps bouncing.'),
                default => __('Keeps spinning.'),
            };
        }

        if ($custom) {
            $sentences[] = __('It also moves in a way of its own.');
        }

        return implode(' ', $sentences);
    }
}
