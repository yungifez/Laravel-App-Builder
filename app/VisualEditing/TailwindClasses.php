<?php

namespace App\VisualEditing;

use InvalidArgumentException;

/**
 * Reads and writes visual properties (width, space, layout, border, corners,
 * columns) as Tailwind utility classes, per device.
 *
 * Devices are Tailwind's own breakpoints: "base" (every screen, so phones),
 * "md" (tablets and up) and "lg" (desktops). A class with any other variant
 * (hover:, sm:, dark:) or one this adapter does not know is never touched.
 *
 * Spacing is written with theme-relative utilities where Tailwind v4 has one
 * (15px is `p-3.75`), and as an arbitrary value otherwise.
 */
class TailwindClasses
{
    /**
     * The devices an owner can edit, from the smallest.
     */
    public const DEVICES = ['base', 'md', 'lg'];

    /**
     * The properties an owner can edit, in the order the inspector shows them.
     */
    public const PROPERTIES = [
        'layout', 'direction', 'wrap', 'align', 'justify', 'columns', 'gap',
        'width', 'padding_x', 'padding_y', 'margin_x', 'margin_y', 'border', 'radius',
    ];

    protected const KEYWORDS = [
        'layout' => ['block' => 'block', 'flex' => 'flex', 'grid' => 'grid', 'hidden' => 'hidden', 'inline' => 'inline', 'inline-block' => 'inline-block', 'inline-flex' => 'inline-flex', 'inline-grid' => 'inline-grid'],
        'direction' => ['flex-row' => 'across', 'flex-col' => 'down'],
        'wrap' => ['flex-wrap' => 'wrap', 'flex-nowrap' => 'nowrap'],
        'align' => ['items-start' => 'start', 'items-center' => 'center', 'items-end' => 'end', 'items-stretch' => 'stretch', 'items-baseline' => 'baseline'],
        'justify' => ['justify-start' => 'start', 'justify-center' => 'center', 'justify-end' => 'end', 'justify-between' => 'between', 'justify-around' => 'around', 'justify-evenly' => 'evenly'],
        'radius' => ['rounded-none' => 'none', 'rounded-xs' => 'xs', 'rounded-sm' => 'sm', 'rounded' => 'sm', 'rounded-md' => 'md', 'rounded-lg' => 'lg', 'rounded-xl' => 'xl', 'rounded-2xl' => '2xl', 'rounded-3xl' => '3xl', 'rounded-4xl' => '4xl', 'rounded-full' => 'full'],
    ];

    protected const WIDTH_KEYWORDS = ['full' => 'full', 'auto' => 'auto', 'fit' => 'fit', 'screen' => 'screen', 'min' => 'min', 'max' => 'max'];

    protected const SIDES = ['top', 'right', 'bottom', 'left'];

    protected const SIDE_PREFIXES = [
        '' => ['top', 'right', 'bottom', 'left'],
        'x' => ['right', 'left'],
        'y' => ['top', 'bottom'],
        't' => ['top'],
        'r' => ['right'],
        'b' => ['bottom'],
        'l' => ['left'],
    ];

    /**
     * Get the values each device sets explicitly. A side-based property is
     * "mixed" when its two sides differ.
     *
     * @return array<string, array<string, int|float|string>>
     */
    public static function read(string $classes): array
    {
        $values = array_fill_keys(self::DEVICES, []);

        foreach (self::tokens($classes) as $token) {
            $parsed = self::parse($token);

            if ($parsed === null) {
                continue;
            }

            [$device, $property, $value] = $parsed;

            if (is_array($value)) {
                $sides = $values[$device][$property] ?? [];
                $values[$device][$property] = array_replace(is_array($sides) ? $sides : [], $value);
            } elseif (($values[$device][$property] ?? null) !== 'mixed') {
                $values[$device][$property] = $value;
            }
        }

        foreach ($values as $device => $properties) {
            $values[$device] = self::collapseSides($properties);
        }

        return $values;
    }

    /**
     * Get the value that applies at each device, from the device itself or
     * the nearest smaller one.
     *
     * @return array<string, array<string, array{value: int|float|string, from: string}>>
     */
    public static function effective(string $classes): array
    {
        $explicit = self::read($classes);
        $effective = [];
        $current = [];

        foreach (self::DEVICES as $device) {
            foreach ($explicit[$device] as $property => $value) {
                $current[$property] = ['value' => $value, 'from' => $device];
            }

            $effective[$device] = $current;
        }

        return $effective;
    }

    /**
     * Set properties for one device, keeping every class that does not
     * belong to them. A null value removes the property at that device.
     *
     * @param  array<string, int|float|string|null>  $changes
     *
     * @throws InvalidArgumentException for an unknown device, property or value.
     */
    public static function write(string $classes, string $device, array $changes): string
    {
        if (! in_array($device, self::DEVICES, true)) {
            throw new InvalidArgumentException("Unknown device [{$device}].");
        }

        $tokens = self::tokens($classes);
        $groups = [];

        foreach ($changes as $property => $value) {
            if (! in_array($property, self::PROPERTIES, true)) {
                throw new InvalidArgumentException("Unknown property [{$property}].");
            }

            $groups[self::group($property)][$property] = $value;
        }

        foreach ($groups as $group => $groupChanges) {
            $tokens = self::writeGroup($tokens, $device, $group, $groupChanges);
        }

        return implode(' ', $tokens);
    }

    /**
     * Get the utility for a spacing value in pixels, without a prefix:
     * 16 is "4", 15 is "3.75", 1 is "px" and 12.5 is "[12.5px]".
     */
    public static function spacing(int|float $pixels): string
    {
        if ($pixels == 1) {
            return 'px';
        }

        if (floor($pixels) == $pixels) {
            return self::number($pixels / 4);
        }

        return '['.self::number($pixels).'px]';
    }

    /**
     * Split a class attribute into its classes.
     *
     * @return list<string>
     */
    protected static function tokens(string $classes): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($classes)) ?: [], fn (string $token) => $token !== ''));
    }

    /**
     * Understand one class: its device, property and value. Side-based
     * properties return their sides.
     *
     * @return array{0: string, 1: string, 2: int|float|string|array<string, int|float|string>}|null
     */
    protected static function parse(string $token): ?array
    {
        $parts = explode(':', $token);
        $utility = (string) array_pop($parts);

        if (count($parts) > 1 || str_starts_with($utility, '!') || str_ends_with($utility, '!')) {
            return null;
        }

        $device = $parts === [] ? 'base' : $parts[0];

        if (! in_array($device, self::DEVICES, true)) {
            return null;
        }

        // One corner or side rounded on its own: the corners differ, and
        // setting the corners replaces these classes.
        if (preg_match('/^rounded-(?:[trbl]|tl|tr|br|bl|[se]|ss|se|es|ee)(?:-(?:none|xs|sm|md|lg|xl|[234]xl|full))?$/', $utility) === 1) {
            return [$device, 'radius', 'mixed'];
        }

        foreach (self::KEYWORDS as $property => $keywords) {
            if (isset($keywords[$utility])) {
                return [$device, $property, $keywords[$utility]];
            }
        }

        if (preg_match('/^grid-cols-(\d+)$/', $utility, $match) === 1) {
            return [$device, 'columns', (int) $match[1]];
        }

        if (preg_match('/^border(?:-(\d+|\[(\d+(?:\.\d+)?)px\]))?$/', $utility, $match) === 1) {
            return [$device, 'border', isset($match[2]) ? self::numeric($match[2]) : (isset($match[1]) ? (int) $match[1] : 1)];
        }

        if (preg_match('/^gap-(.+)$/', $utility, $match) === 1 && ($pixels = self::pixels($match[1])) !== null) {
            return [$device, 'gap', $pixels];
        }

        if (preg_match('/^w-(.+)$/', $utility, $match) === 1 && ($width = self::width($match[1])) !== null) {
            return [$device, 'width', $width];
        }

        if (preg_match('/^(-?)([pm])([xytrbl]?)-(.+)$/', $utility, $match) === 1) {
            [, $negative, $kind, $sides, $amount] = $match;
            $value = $kind === 'm' && $amount === 'auto' && $negative === '' ? 'auto' : self::pixels($amount);

            if ($value === null || ($negative === '-' && $kind === 'p')) {
                return null;
            }

            if ($negative === '-' && is_numeric($value)) {
                $value = -$value;
            }

            return [$device, $kind === 'p' ? 'padding' : 'margin', array_fill_keys(self::SIDE_PREFIXES[$sides], $value)];
        }

        return null;
    }

    /**
     * Turn padding and margin sides into the horizontal and vertical values
     * the inspector shows.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, int|float|string>
     */
    protected static function collapseSides(array $properties): array
    {
        foreach (['padding', 'margin'] as $kind) {
            if (! isset($properties[$kind])) {
                continue;
            }

            $sides = $properties[$kind];
            unset($properties[$kind]);

            foreach (['x' => ['left', 'right'], 'y' => ['top', 'bottom']] as $axis => [$first, $second]) {
                if (! isset($sides[$first]) && ! isset($sides[$second])) {
                    continue;
                }

                $properties["{$kind}_{$axis}"] = ($sides[$first] ?? null) === ($sides[$second] ?? null) ? $sides[$first] : 'mixed';
            }
        }

        return $properties;
    }

    /**
     * Get the group of classes a property is written in: padding and
     * margin sides are written together.
     */
    protected static function group(string $property): string
    {
        return match ($property) {
            'padding_x', 'padding_y' => 'padding',
            'margin_x', 'margin_y' => 'margin',
            default => $property,
        };
    }

    /**
     * Replace one group's classes at the device. The new classes go where
     * the first old one was, or at the end.
     *
     * @param  list<string>  $tokens
     * @param  array<string, int|float|string|null>  $changes
     * @return list<string>
     */
    protected static function writeGroup(array $tokens, string $device, string $group, array $changes): array
    {
        $position = null;
        $sides = [];
        $kept = [];

        foreach ($tokens as $token) {
            $parsed = self::parse($token);

            if ($parsed !== null && $parsed[0] === $device && $parsed[1] === $group) {
                $position ??= count($kept);

                if (is_array($parsed[2])) {
                    $sides = array_replace($sides, $parsed[2]);
                }

                continue;
            }

            $kept[] = $token;
        }

        if (in_array($group, ['padding', 'margin'], true)) {
            foreach ($changes as $property => $value) {
                foreach (str_ends_with($property, '_x') ? ['left', 'right'] : ['top', 'bottom'] as $side) {
                    $sides[$side] = $value;
                }
            }

            $new = self::sideClasses($group === 'padding' ? 'p' : 'm', array_filter($sides, fn ($value) => $value !== null));
        } else {
            $value = $changes[$group];
            $new = $value === null ? [] : [self::utility($group, $value)];
        }

        $prefix = $device === 'base' ? '' : "{$device}:";
        $new = array_map(fn (string $utility) => $prefix.$utility, $new);

        array_splice($kept, $position ?? count($kept), 0, $new);

        return $kept;
    }

    /**
     * Write padding or margin sides in the shortest form.
     *
     * @param  array<string, int|float|string>  $sides
     * @return list<string>
     */
    protected static function sideClasses(string $kind, array $sides): array
    {
        $value = fn (int|float|string $amount) => match (true) {
            $amount === 'auto' => 'auto',
            is_string($amount) => throw new InvalidArgumentException("Invalid spacing [{$amount}]."),
            default => self::spacing(abs($amount)),
        };
        $class = fn (string $side, int|float|string $amount) => (is_numeric($amount) && $amount < 0 ? '-' : '')."{$kind}{$side}-".$value($amount);

        $x = isset($sides['left'], $sides['right']) && $sides['left'] === $sides['right'] ? $sides['left'] : null;
        $y = isset($sides['top'], $sides['bottom']) && $sides['top'] === $sides['bottom'] ? $sides['top'] : null;

        if ($x !== null && $x === $y) {
            return [$class('', $x)];
        }

        $classes = [];

        if ($x !== null) {
            $classes[] = $class('x', $x);
        } else {
            foreach (['right' => 'r', 'left' => 'l'] as $side => $short) {
                if (isset($sides[$side])) {
                    $classes[] = $class($short, $sides[$side]);
                }
            }
        }

        if ($y !== null) {
            $classes[] = $class('y', $y);
        } else {
            foreach (['top' => 't', 'bottom' => 'b'] as $side => $short) {
                if (isset($sides[$side])) {
                    $classes[] = $class($short, $sides[$side]);
                }
            }
        }

        return $classes;
    }

    /**
     * Write one property's utility.
     *
     * @throws InvalidArgumentException for a value the property cannot take.
     */
    protected static function utility(string $property, int|float|string $value): string
    {
        if (isset(self::KEYWORDS[$property])) {
            $utility = array_search($value, $property === 'radius' ? array_diff_key(self::KEYWORDS[$property], ['rounded' => true]) : self::KEYWORDS[$property], true);

            if ($utility === false) {
                throw new InvalidArgumentException("Invalid {$property} [{$value}].");
            }

            return (string) $utility;
        }

        return match ($property) {
            'columns' => is_int($value) && $value >= 1 && $value <= 12 ? "grid-cols-{$value}" : throw new InvalidArgumentException("Invalid columns [{$value}]."),
            'border' => match (true) {
                $value === 1 => 'border',
                in_array($value, [0, 2, 4, 8], true) => "border-{$value}",
                is_numeric($value) && $value > 0 => 'border-['.self::number((float) $value).'px]',
                default => throw new InvalidArgumentException("Invalid border [{$value}]."),
            },
            'gap' => (is_int($value) || is_float($value)) && $value >= 0 ? 'gap-'.self::spacing($value) : throw new InvalidArgumentException("Invalid gap [{$value}]."),
            'width' => 'w-'.self::widthUtility($value),
            default => throw new InvalidArgumentException("Unknown property [{$property}]."),
        };
    }

    /**
     * Read a width: a keyword, pixels, or a percentage such as "50%".
     */
    protected static function width(string $amount): int|float|string|null
    {
        if (isset(self::WIDTH_KEYWORDS[$amount])) {
            return self::WIDTH_KEYWORDS[$amount];
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $amount, $match) === 1 && (int) $match[2] > 0) {
            return self::number(round((int) $match[1] / (int) $match[2] * 100, 2)).'%';
        }

        if (preg_match('/^\[(\d+(?:\.\d+)?)%\]$/', $amount, $match) === 1) {
            return self::number((float) $match[1]).'%';
        }

        return self::pixels($amount);
    }

    /**
     * Write a width's utility suffix.
     */
    protected static function widthUtility(int|float|string $value): string
    {
        if (is_string($value) && isset(self::WIDTH_KEYWORDS[$value])) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)%$/', $value, $match) === 1) {
            $percent = (float) $match[1];

            foreach ([[1, 2], [1, 3], [2, 3], [1, 4], [3, 4], [1, 5], [2, 5], [3, 5], [4, 5]] as [$top, $bottom]) {
                if (abs($percent - $top / $bottom * 100) < 0.01) {
                    return "{$top}/{$bottom}";
                }
            }

            return $percent == 100 ? 'full' : '['.self::number($percent).'%]';
        }

        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return self::spacing($value);
        }

        throw new InvalidArgumentException("Invalid width [{$value}].");
    }

    /**
     * Read a spacing amount in pixels: "4" is 16, "px" is 1, "[15px]" is 15
     * and "[1rem]" is 16.
     */
    protected static function pixels(string $amount): int|float|null
    {
        if ($amount === 'px') {
            return 1;
        }

        if (preg_match('/^\d+(\.\d+)?$/', $amount) === 1) {
            return self::numeric((string) ((float) $amount * 4));
        }

        if (preg_match('/^\[(\d+(?:\.\d+)?)(px|rem)\]$/', $amount, $match) === 1) {
            return self::numeric((string) ((float) $match[1] * ($match[2] === 'rem' ? 16 : 1)));
        }

        return null;
    }

    protected static function numeric(string $value): int|float
    {
        return floor((float) $value) == (float) $value ? (int) $value : (float) $value;
    }

    protected static function number(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
