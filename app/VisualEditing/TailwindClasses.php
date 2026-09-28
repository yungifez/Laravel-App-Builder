<?php

namespace App\VisualEditing;

use InvalidArgumentException;

/**
 * Reads and writes visual properties (size, space, layout, border, corners,
 * shadow, columns, turn, move, see-through, text and colours) as Tailwind
 * utility classes, per device.
 *
 * Devices are Tailwind's own breakpoints: "base" (every screen, so phones),
 * "md" (tablets and up) and "lg" (desktops). A class with any other variant
 * (hover:, sm:, dark:) or one this adapter does not know is never touched.
 *
 * Spacing is written with theme-relative utilities where Tailwind v4 has one
 * (15px is `p-3.75`), and as an arbitrary value otherwise (12.5px is
 * `p-[12.5px]`, 7.5 degrees is `rotate-[7.5deg]`). Whether a value sits on
 * the scale is the inspector's choice (it snaps unless the owner fine
 * tunes); this class writes any value it is given. Colours are the
 * app's theme tokens only; any other colour reads as "custom", and choosing
 * a token replaces it.
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
        'width', 'height', 'max_width', 'padding_x', 'padding_y', 'margin_x', 'margin_y', 'border', 'border_color', 'radius', 'shadow',
        'rotate', 'translate_x', 'translate_y', 'opacity',
        'text_size', 'text_weight', 'text_align', 'font_style', 'text_decoration', 'line_height', 'text_color', 'background',
    ];

    protected const KEYWORDS = [
        'layout' => ['block' => 'block', 'flex' => 'flex', 'grid' => 'grid', 'hidden' => 'hidden', 'inline' => 'inline', 'inline-block' => 'inline-block', 'inline-flex' => 'inline-flex', 'inline-grid' => 'inline-grid'],
        'direction' => ['flex-row' => 'across', 'flex-col' => 'down'],
        'wrap' => ['flex-wrap' => 'wrap', 'flex-nowrap' => 'nowrap'],
        'align' => ['items-start' => 'start', 'items-center' => 'center', 'items-end' => 'end', 'items-stretch' => 'stretch', 'items-baseline' => 'baseline'],
        'justify' => ['justify-start' => 'start', 'justify-center' => 'center', 'justify-end' => 'end', 'justify-between' => 'between', 'justify-around' => 'around', 'justify-evenly' => 'evenly'],
        'radius' => ['rounded-none' => 'none', 'rounded-xs' => 'xs', 'rounded-sm' => 'sm', 'rounded' => 'sm', 'rounded-md' => 'md', 'rounded-lg' => 'lg', 'rounded-xl' => 'xl', 'rounded-2xl' => '2xl', 'rounded-3xl' => '3xl', 'rounded-4xl' => '4xl', 'rounded-full' => 'full'],
        'max_width' => ['max-w-none' => 'none', 'max-w-xs' => 'xs', 'max-w-sm' => 'sm', 'max-w-md' => 'md', 'max-w-lg' => 'lg', 'max-w-xl' => 'xl', 'max-w-2xl' => '2xl', 'max-w-3xl' => '3xl', 'max-w-4xl' => '4xl', 'max-w-5xl' => '5xl', 'max-w-6xl' => '6xl', 'max-w-7xl' => '7xl', 'max-w-prose' => 'prose', 'max-w-full' => 'full'],
        'shadow' => ['shadow-none' => 'none', 'shadow-2xs' => '2xs', 'shadow-xs' => 'xs', 'shadow-sm' => 'sm', 'shadow' => 'sm', 'shadow-md' => 'md', 'shadow-lg' => 'lg', 'shadow-xl' => 'xl', 'shadow-2xl' => '2xl'],
        'text_size' => ['text-xs' => 'xs', 'text-sm' => 'sm', 'text-base' => 'base', 'text-lg' => 'lg', 'text-xl' => 'xl', 'text-2xl' => '2xl', 'text-3xl' => '3xl', 'text-4xl' => '4xl', 'text-5xl' => '5xl', 'text-6xl' => '6xl'],
        'text_weight' => ['font-light' => 'light', 'font-normal' => 'normal', 'font-medium' => 'medium', 'font-semibold' => 'semibold', 'font-bold' => 'bold'],
        'text_align' => ['text-left' => 'left', 'text-center' => 'center', 'text-right' => 'right', 'text-justify' => 'justify', 'text-start' => 'start', 'text-end' => 'end'],
        'font_style' => ['italic' => 'italic', 'not-italic' => 'normal'],
        'text_decoration' => ['underline' => 'underline', 'line-through' => 'line-through', 'no-underline' => 'none'],
        'line_height' => ['leading-none' => 'none', 'leading-tight' => 'tight', 'leading-snug' => 'snug', 'leading-normal' => 'normal', 'leading-relaxed' => 'relaxed', 'leading-loose' => 'loose'],
        'text_color' => ['text-foreground' => 'foreground', 'text-muted-foreground' => 'muted-foreground', 'text-primary' => 'primary', 'text-primary-foreground' => 'primary-foreground', 'text-secondary-foreground' => 'secondary-foreground', 'text-accent-foreground' => 'accent-foreground', 'text-destructive' => 'destructive'],
        'border_color' => ['border-border' => 'border', 'border-input' => 'input', 'border-foreground' => 'foreground', 'border-muted-foreground' => 'muted-foreground', 'border-primary' => 'primary', 'border-accent' => 'accent', 'border-destructive' => 'destructive', 'border-transparent' => 'transparent'],
        'background' => ['bg-transparent' => 'transparent', 'bg-background' => 'background', 'bg-card' => 'card', 'bg-muted' => 'muted', 'bg-primary' => 'primary', 'bg-secondary' => 'secondary', 'bg-accent' => 'accent', 'bg-destructive' => 'destructive'],
    ];

    /**
     * Colours that are not theme tokens: Tailwind's palette, white and
     * black, a token with an opacity, or a written colour code.
     */
    /**
     * The properties that take a colour from the app's theme.
     */
    protected const COLORS = ['text_color', 'background', 'border_color'];

    protected const CUSTOM_COLOR = '/^(text|bg|border)-(?:(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}|white|black|(?:foreground|muted-foreground|primary|primary-foreground|secondary|secondary-foreground|accent|accent-foreground|destructive|background|card|muted|border|input)|\[#[0-9a-fA-F]{3,8}\])(?:\/\d+)?$/';

    /**
     * Properties written as a prefix and an amount, such as `w-60`, `h-1/2`
     * or `-rotate-12`. The unit says how the amount reads and writes:
     *
     * - spacing: pixels on Tailwind's spacing scale (`4` is 16px)
     * - length: spacing, a fraction or percentage, or one of the keywords
     * - degrees: an angle (`rotate-45`, `rotate-[7.5deg]`)
     * - percent: 0 to 100 (`opacity-50`, `opacity-[37.5%]`)
     *
     * Negative amounts are written with a leading "-" where allowed.
     */
    protected const MEASURES = [
        'gap' => ['prefix' => 'gap', 'unit' => 'spacing'],
        'width' => ['prefix' => 'w', 'unit' => 'length', 'keywords' => ['full', 'auto', 'fit', 'screen', 'min', 'max']],
        'height' => ['prefix' => 'h', 'unit' => 'length', 'keywords' => ['full', 'auto', 'fit', 'screen', 'min', 'max', 'svh', 'dvh']],
        'rotate' => ['prefix' => 'rotate', 'unit' => 'degrees', 'negative' => true],
        'translate_x' => ['prefix' => 'translate-x', 'unit' => 'length', 'keywords' => ['full'], 'negative' => true],
        'translate_y' => ['prefix' => 'translate-y', 'unit' => 'length', 'keywords' => ['full'], 'negative' => true],
        'opacity' => ['prefix' => 'opacity', 'unit' => 'percent'],
    ];

    /**
     * Fractions Tailwind writes as `1/2`, from percentages.
     */
    protected const FRACTIONS = [[1, 2], [1, 3], [2, 3], [1, 4], [3, 4], [1, 5], [2, 5], [3, 5], [4, 5]];

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

        // A theme colour already changes in dark mode. The part's own
        // dark-mode colour would hide the chosen one there, so it goes.
        foreach ($changes as $property => $value) {
            if ($value !== null && in_array($property, self::COLORS, true)) {
                $tokens = array_values(array_filter(
                    $tokens,
                    fn (string $token) => ! str_starts_with($token, 'dark:') || (self::parse(substr($token, 5))[1] ?? null) !== $property,
                ));
            }
        }

        return implode(' ', $tokens);
    }

    /**
     * Get a class list with its spacing made regular, so two lists that
     * differ only in whitespace compare as equal.
     */
    public static function normalize(string $classes): string
    {
        return implode(' ', preg_split('/\s+/', trim($classes), flags: PREG_SPLIT_NO_EMPTY) ?: []);
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

        if (preg_match(self::CUSTOM_COLOR, $utility, $match) === 1) {
            return [$device, match ($match[1]) {
                'bg' => 'background',
                'border' => 'border_color',
                default => 'text_color',
            }, 'custom'];
        }

        if (preg_match('/^grid-cols-(\d+)$/', $utility, $match) === 1) {
            return [$device, 'columns', (int) $match[1]];
        }

        if (preg_match('/^border(?:-(\d+|\[(\d+(?:\.\d+)?)px\]))?$/', $utility, $match) === 1) {
            return [$device, 'border', isset($match[2]) ? self::numeric($match[2]) : (isset($match[1]) ? (int) $match[1] : 1)];
        }

        foreach (self::MEASURES as $property => $measure) {
            if (preg_match('/^(-?)'.preg_quote($measure['prefix'], '/').'-(.+)$/', $utility, $match) === 1
                && ($value = self::readMeasure($measure, $match[2], $match[1] === '-')) !== null) {
                return [$device, $property, $value];
            }
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
            default => isset(self::MEASURES[$property])
                ? self::writeMeasure(self::MEASURES[$property], $value) ?? throw new InvalidArgumentException("Invalid {$property} [{$value}].")
                : throw new InvalidArgumentException("Unknown property [{$property}]."),
        };
    }

    /**
     * Read a measure's amount, or null when the class is not one of its
     * utilities.
     *
     * @param  array{prefix: string, unit: string, keywords?: list<string>, negative?: bool}  $measure
     */
    protected static function readMeasure(array $measure, string $amount, bool $negative): int|float|string|null
    {
        if ($negative && ! ($measure['negative'] ?? false)) {
            return null;
        }

        $value = match ($measure['unit']) {
            'spacing' => self::pixels($amount),
            'length' => self::length($amount, $measure['keywords'] ?? []),
            'degrees' => preg_match('/^(?:(\d+(?:\.\d+)?)|\[(-?\d+(?:\.\d+)?)deg\])$/', $amount, $match) === 1
                ? self::numeric($match[1] !== '' ? $match[1] : $match[2])
                : null,
            'percent' => preg_match('/^(?:(\d+)|\[(\d+(?:\.\d+)?)%\])$/', $amount, $match) === 1
                ? self::numeric($match[1] !== '' ? $match[1] : $match[2])
                : null,
            default => null,
        };

        if (! $negative || $value === null) {
            return $value;
        }

        return match (true) {
            is_string($value) && str_ends_with($value, '%') => '-'.$value,
            is_string($value) => null,
            default => -$value,
        };
    }

    /**
     * Write a measure's utility, or null when the value does not fit it.
     *
     * @param  array{prefix: string, unit: string, keywords?: list<string>, negative?: bool}  $measure
     */
    protected static function writeMeasure(array $measure, int|float|string $value): ?string
    {
        $negative = false;

        if ((is_int($value) || is_float($value)) && $value < 0) {
            $negative = true;
            $value = abs($value);
        } elseif (is_string($value) && str_starts_with($value, '-')) {
            $negative = true;
            $value = substr($value, 1);
        }

        if ($negative && ! ($measure['negative'] ?? false)) {
            return null;
        }

        $amount = match ($measure['unit']) {
            'spacing' => is_int($value) || is_float($value) ? self::spacing($value) : null,
            'length' => self::lengthUtility($value, $measure['keywords'] ?? []),
            'degrees' => match (true) {
                is_int($value) || (is_float($value) && floor($value) == $value) => (string) (int) $value,
                is_float($value) => '['.self::number($value).'deg]',
                default => null,
            },
            'percent' => match (true) {
                ! is_int($value) && ! is_float($value), $value > 100 => null,
                floor($value) == $value => (string) (int) $value,
                default => '['.self::number($value).'%]',
            },
            default => null,
        };

        return $amount === null ? null : ($negative ? '-' : '').$measure['prefix'].'-'.$amount;
    }

    /**
     * Read a length: a keyword, pixels, or a percentage such as "50%".
     *
     * @param  list<string>  $keywords
     */
    protected static function length(string $amount, array $keywords): int|float|string|null
    {
        if (in_array($amount, $keywords, true)) {
            return $amount;
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
     * Write a length's utility suffix.
     *
     * @param  list<string>  $keywords
     */
    protected static function lengthUtility(int|float|string $value, array $keywords): ?string
    {
        if (is_string($value) && in_array($value, $keywords, true)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?)%$/', $value, $match) === 1) {
            $percent = (float) $match[1];

            foreach (self::FRACTIONS as [$top, $bottom]) {
                if (abs($percent - $top / $bottom * 100) < 0.01) {
                    return "{$top}/{$bottom}";
                }
            }

            return $percent == 100 && in_array('full', $keywords, true) ? 'full' : '['.self::number($percent).'%]';
        }

        return is_int($value) || is_float($value) ? self::spacing($value) : null;
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
