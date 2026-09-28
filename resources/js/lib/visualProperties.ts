import type { Device, Drawn, VisualProperty, VisualValue } from '@/types';

/**
 * The devices the owner edits for, and how wide the preview is for each.
 * They are Tailwind's breakpoints: a phone is every screen, a tablet is `md`
 * (768px and up) and a desktop is `lg` (1024px and up).
 */
export const devices: { key: Device; label: string; width: number | null }[] = [
    { key: 'base', label: 'Phone', width: 390 },
    { key: 'md', label: 'Tablet', width: 820 },
    { key: 'lg', label: 'Desktop', width: null },
];

/**
 * The steps a number snaps to. Spacing follows Tailwind's spacing scale;
 * the others are even steps.
 */
export type Scale = 'spacing' | 'border' | 'degrees' | 'percent' | 'count';

export type Option = {
    value: VisualValue;
    label: string;
    /** A word or sign for a narrow button. */
    short?: string;
};

export type PropertyInput =
    | { kind: 'choice'; options: Option[] }
    | {
          kind: 'measure';
          scale: Scale;
          unit: string;
          min?: number;
          max?: number;
          allowNegative?: boolean;
          /** Words the value can be instead of a number. */
          keywords?: Option[];
      };

export type PropertyDefinition = {
    key: VisualProperty;
    label: string;
    /** A word or two, for the label next to the part while dragging. */
    short?: string;
    group: string;
    input: PropertyInput;
    /** Shown only when the element is laid out this way. */
    when?: 'flex' | 'grid' | 'flex-or-grid';
};

/**
 * The properties the inspector shows, in owner words. Values are what the
 * server reads and writes: pixels, percentages and words.
 */
export const properties: PropertyDefinition[] = [
    {
        key: 'layout',
        label: 'Arrange contents',
        group: 'Layout',
        input: {
            kind: 'choice',
            options: [
                { value: 'block', label: 'One under another' },
                { value: 'flex', label: 'In a line' },
                { value: 'grid', label: 'In a grid' },
                { value: 'inline', label: 'Inside the text' },
                { value: 'inline-block', label: 'Inside the text, as a box' },
                {
                    value: 'inline-flex',
                    label: 'Inside the text, contents in a line',
                },
                {
                    value: 'inline-grid',
                    label: 'Inside the text, contents in a grid',
                },
                { value: 'hidden', label: 'Hidden' },
            ],
        },
    },
    {
        key: 'direction',
        label: 'Direction',
        group: 'Layout',
        when: 'flex',
        input: {
            kind: 'choice',
            options: [
                { value: 'across', label: 'Across' },
                { value: 'down', label: 'Down' },
            ],
        },
    },
    {
        key: 'wrap',
        label: 'When there is no room',
        group: 'Layout',
        when: 'flex',
        input: {
            kind: 'choice',
            options: [
                { value: 'wrap', label: 'Move to the next line' },
                { value: 'nowrap', label: 'Stay on one line' },
            ],
        },
    },
    {
        key: 'columns',
        label: 'Columns',
        group: 'Layout',
        when: 'grid',
        input: {
            kind: 'measure',
            scale: 'count',
            unit: 'cols',
            min: 1,
            max: 12,
        },
    },
    {
        key: 'align',
        label: 'Line up',
        group: 'Layout',
        when: 'flex-or-grid',
        input: {
            kind: 'choice',
            options: [
                { value: 'start', label: 'At the start' },
                { value: 'center', label: 'In the middle' },
                { value: 'end', label: 'At the end' },
                { value: 'stretch', label: 'Stretched' },
                { value: 'baseline', label: 'On the text line' },
            ],
        },
    },
    {
        key: 'justify',
        label: 'Spread',
        group: 'Layout',
        when: 'flex-or-grid',
        input: {
            kind: 'choice',
            options: [
                { value: 'start', label: 'Packed at the start' },
                { value: 'center', label: 'Packed in the middle' },
                { value: 'end', label: 'Packed at the end' },
                { value: 'between', label: 'Spread to the edges' },
                { value: 'around', label: 'Spread with space around' },
                { value: 'evenly', label: 'Spread evenly' },
            ],
        },
    },
    {
        key: 'gap',
        label: 'Space between items',
        short: 'Gap',
        group: 'Layout',
        when: 'flex-or-grid',
        input: { kind: 'measure', scale: 'spacing', unit: 'px' },
    },
    {
        key: 'width',
        label: 'Width',
        short: 'Width',
        group: 'Size',
        input: {
            kind: 'measure',
            scale: 'spacing',
            unit: 'px',
            keywords: [
                { value: 'auto', label: 'Automatic', short: 'Auto' },
                { value: 'fit', label: 'Fit its contents', short: 'Fit' },
                { value: 'full', label: 'Fill the space', short: 'Fill' },
                { value: '75%', label: 'Three quarters' },
                { value: '66.67%', label: 'Two thirds' },
                { value: '50%', label: 'Half', short: '½' },
                { value: '33.33%', label: 'One third', short: '⅓' },
                { value: '25%', label: 'One quarter', short: '¼' },
            ],
        },
    },
    {
        key: 'height',
        label: 'Height',
        short: 'Height',
        group: 'Size',
        input: {
            kind: 'measure',
            scale: 'spacing',
            unit: 'px',
            keywords: [
                { value: 'auto', label: 'Automatic', short: 'Auto' },
                { value: 'full', label: 'Fill the space', short: 'Fill' },
                { value: 'screen', label: 'The whole screen', short: 'Screen' },
            ],
        },
    },
    {
        key: 'max_width',
        label: 'Widest it gets',
        group: 'Size',
        input: {
            kind: 'choice',
            options: [
                { value: 'none', label: 'No limit' },
                { value: 'full', label: 'The space it is in' },
                { value: 'prose', label: 'A comfortable reading width' },
                { value: 'xs', label: '320 px' },
                { value: 'sm', label: '384 px' },
                { value: 'md', label: '448 px' },
                { value: 'lg', label: '512 px' },
                { value: 'xl', label: '576 px' },
                { value: '2xl', label: '672 px' },
                { value: '3xl', label: '768 px' },
                { value: '4xl', label: '896 px' },
                { value: '5xl', label: '1024 px' },
                { value: '6xl', label: '1152 px' },
                { value: '7xl', label: '1280 px' },
            ],
        },
    },
    {
        key: 'padding_x',
        label: 'Space inside, left and right',
        short: 'Inside',
        group: 'Space',
        input: { kind: 'measure', scale: 'spacing', unit: 'px' },
    },
    {
        key: 'padding_y',
        label: 'Space inside, top and bottom',
        short: 'Inside',
        group: 'Space',
        input: { kind: 'measure', scale: 'spacing', unit: 'px' },
    },
    {
        key: 'margin_x',
        label: 'Space outside, left and right',
        short: 'Outside',
        group: 'Space',
        input: {
            kind: 'measure',
            scale: 'spacing',
            unit: 'px',
            allowNegative: true,
            keywords: [{ value: 'auto', label: 'Centred', short: 'auto' }],
        },
    },
    {
        key: 'margin_y',
        label: 'Space outside, top and bottom',
        short: 'Outside',
        group: 'Space',
        input: {
            kind: 'measure',
            scale: 'spacing',
            unit: 'px',
            allowNegative: true,
        },
    },
    {
        key: 'border',
        label: 'Border',
        group: 'Edges',
        input: { kind: 'measure', scale: 'border', unit: 'px' },
    },
    {
        key: 'radius',
        label: 'Corners',
        group: 'Edges',
        input: {
            kind: 'choice',
            options: [
                { value: 'none', label: 'Square' },
                { value: 'sm', label: 'Slightly rounded' },
                { value: 'md', label: 'Rounded' },
                { value: 'lg', label: 'More rounded' },
                { value: 'xl', label: 'Very rounded' },
                { value: '2xl', label: 'Extra rounded' },
                { value: 'full', label: 'Pill' },
            ],
        },
    },
    {
        key: 'shadow',
        label: 'Shadow',
        group: 'Edges',
        input: {
            kind: 'choice',
            options: [
                { value: 'none', label: 'None' },
                { value: '2xs', label: 'Barely there' },
                { value: 'xs', label: 'Faint' },
                { value: 'sm', label: 'Light' },
                { value: 'md', label: 'Medium' },
                { value: 'lg', label: 'Strong' },
                { value: 'xl', label: 'Very strong' },
                { value: '2xl', label: 'Floating' },
            ],
        },
    },
    {
        key: 'rotate',
        label: 'Turn',
        short: 'Turn',
        group: 'Place',
        input: {
            kind: 'measure',
            scale: 'degrees',
            unit: '°',
            min: -360,
            max: 360,
            allowNegative: true,
        },
    },
    {
        key: 'translate_x',
        label: 'Move across',
        short: 'Across',
        group: 'Place',
        input: {
            kind: 'measure',
            scale: 'spacing',
            unit: 'px',
            allowNegative: true,
        },
    },
    {
        key: 'translate_y',
        label: 'Move down',
        short: 'Down',
        group: 'Place',
        input: {
            kind: 'measure',
            scale: 'spacing',
            unit: 'px',
            allowNegative: true,
        },
    },
    {
        key: 'opacity',
        label: 'Opacity',
        short: 'Opacity',
        group: 'Place',
        input: {
            kind: 'measure',
            scale: 'percent',
            unit: '%',
            min: 0,
            max: 100,
        },
    },
    {
        key: 'text_size',
        label: 'Text size',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'xs', label: 'Smallest' },
                { value: 'sm', label: 'Small' },
                { value: 'base', label: 'Normal' },
                { value: 'lg', label: 'Large' },
                { value: 'xl', label: 'Larger' },
                { value: '2xl', label: 'Heading' },
                { value: '3xl', label: 'Big heading' },
                { value: '4xl', label: 'Bigger heading' },
                { value: '5xl', label: 'Huge' },
                { value: '6xl', label: 'Largest' },
            ],
        },
    },
    {
        key: 'text_weight',
        label: 'Text weight',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'light', label: 'Light' },
                { value: 'normal', label: 'Normal' },
                { value: 'medium', label: 'Medium' },
                { value: 'semibold', label: 'Semi-bold', short: 'Semi' },
                { value: 'bold', label: 'Bold' },
            ],
        },
    },
    {
        key: 'text_align',
        label: 'Line up text',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'left', label: 'Left' },
                { value: 'center', label: 'Centre' },
                { value: 'right', label: 'Right' },
                { value: 'justify', label: 'Both edges' },
            ],
        },
    },
    {
        key: 'font_style',
        label: 'Slanted',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'italic', label: 'Slanted' },
                { value: 'normal', label: 'Upright' },
            ],
        },
    },
    {
        key: 'text_decoration',
        label: 'Line on the words',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'underline', label: 'Underlined' },
                { value: 'line-through', label: 'Crossed out' },
                { value: 'none', label: 'No line' },
            ],
        },
    },
    {
        key: 'text_case',
        label: 'Capital letters',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'none', label: 'As typed' },
                { value: 'uppercase', label: 'Capitals' },
                { value: 'capitalize', label: 'Title case', short: 'Title' },
                { value: 'lowercase', label: 'Small letters', short: 'Small' },
            ],
        },
    },
    {
        key: 'border_style',
        label: 'Border line',
        group: 'Fill and edges',
        input: {
            kind: 'choice',
            options: [
                { value: 'solid', label: 'Solid' },
                { value: 'dashed', label: 'Dashed' },
                { value: 'dotted', label: 'Dotted' },
            ],
        },
    },
    {
        key: 'line_clamp',
        label: 'Lines shown',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'none', label: 'All of it', short: 'All' },
                { value: '1', label: '1 line' },
                { value: '2', label: '2 lines' },
                { value: '3', label: '3 lines' },
                { value: '4', label: '4 lines' },
            ],
        },
    },
    {
        key: 'line_height',
        label: 'Space between lines',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'none', label: 'None' },
                { value: 'tight', label: 'Tight' },
                { value: 'snug', label: 'Snug' },
                { value: 'normal', label: 'Normal' },
                { value: 'relaxed', label: 'Relaxed' },
                { value: 'loose', label: 'Loose' },
            ],
        },
    },
    {
        key: 'letter_spacing',
        label: 'Space between letters',
        group: 'Text',
        input: {
            kind: 'choice',
            options: [
                { value: 'tighter', label: 'Tighter' },
                { value: 'tight', label: 'Tight' },
                { value: 'normal', label: 'Normal' },
                { value: 'wide', label: 'Wide' },
                { value: 'wider', label: 'Wider' },
                { value: 'widest', label: 'Widest' },
            ],
        },
    },
    // Colours come from the app's own theme, so they stay right in dark
    // mode and when the theme changes.
    {
        key: 'text_color',
        label: 'Text colour',
        group: 'Colours',
        input: {
            kind: 'choice',
            options: [
                { value: 'foreground', label: 'Normal text' },
                { value: 'muted-foreground', label: 'Quiet text' },
                { value: 'primary', label: 'Main colour' },
                {
                    value: 'primary-foreground',
                    label: 'Text on the main colour',
                },
                {
                    value: 'secondary-foreground',
                    label: 'Text on the second colour',
                },
                {
                    value: 'accent-foreground',
                    label: 'Text on the highlight',
                },
                { value: 'destructive', label: 'Warning' },
            ],
        },
    },
    {
        key: 'border_color',
        label: 'Border colour',
        group: 'Colours',
        input: {
            kind: 'choice',
            options: [
                { value: 'transparent', label: 'None' },
                { value: 'border', label: 'Edge' },
                { value: 'input', label: 'Field edge' },
                { value: 'foreground', label: 'Text' },
                { value: 'muted-foreground', label: 'Quiet text' },
                { value: 'primary', label: 'Main colour' },
                { value: 'accent', label: 'Highlight' },
                { value: 'destructive', label: 'Warning' },
            ],
        },
    },
    {
        key: 'background',
        label: 'Background',
        group: 'Colours',
        input: {
            kind: 'choice',
            options: [
                { value: 'transparent', label: 'None' },
                { value: 'background', label: 'Page' },
                { value: 'card', label: 'Panel' },
                { value: 'muted', label: 'Quiet' },
                { value: 'primary', label: 'Main colour' },
                { value: 'secondary', label: 'Second colour' },
                { value: 'accent', label: 'Highlight' },
                { value: 'destructive', label: 'Warning' },
            ],
        },
    },
    // A drawing (an SVG) paints its insides and its lines apart. Which one
    // shows depends on how it was drawn, so the panel lets the owner try.
    {
        key: 'fill_color',
        label: 'Inside colour',
        group: 'Colours',
        input: {
            kind: 'choice',
            options: [
                { value: 'transparent', label: 'None' },
                { value: 'foreground', label: 'Text' },
                { value: 'muted-foreground', label: 'Quiet text' },
                { value: 'primary', label: 'Main colour' },
                { value: 'accent', label: 'Highlight' },
                { value: 'destructive', label: 'Warning' },
            ],
        },
    },
    {
        key: 'stroke_color',
        label: 'Line colour',
        group: 'Colours',
        input: {
            kind: 'choice',
            options: [
                { value: 'transparent', label: 'None' },
                { value: 'foreground', label: 'Text' },
                { value: 'muted-foreground', label: 'Quiet text' },
                { value: 'primary', label: 'Main colour' },
                { value: 'accent', label: 'Highlight' },
                { value: 'destructive', label: 'Warning' },
            ],
        },
    },
    {
        key: 'object_fit',
        label: 'Picture fit',
        group: 'Picture',
        input: {
            kind: 'choice',
            options: [
                { value: 'cover', label: 'Fill the box', short: 'Fill' },
                { value: 'contain', label: 'Show it whole', short: 'Whole' },
                { value: 'fill', label: 'Stretch it', short: 'Stretch' },
            ],
        },
    },
    {
        key: 'object_position',
        label: 'Picture focus',
        group: 'Picture',
        input: {
            kind: 'choice',
            options: [
                { value: 'top', label: 'Top' },
                { value: 'center', label: 'Middle' },
                { value: 'bottom', label: 'Bottom' },
                { value: 'left', label: 'Left' },
                { value: 'right', label: 'Right' },
            ],
        },
    },
    {
        key: 'aspect_ratio',
        label: 'Picture shape',
        group: 'Picture',
        input: {
            kind: 'choice',
            options: [
                { value: 'auto', label: 'As it is', short: 'Free' },
                { value: 'square', label: 'Square' },
                { value: 'video', label: 'Wide (16:9)', short: 'Wide' },
                { value: '4/3', label: 'Photo (4:3)', short: 'Photo' },
                { value: '3/4', label: 'Tall (3:4)', short: 'Tall' },
            ],
        },
    },
];

// A colour while the pointer is on a part takes the same choices as the
// colour it replaces.
for (const [key, from, label] of [
    ['hover_text_color', 'text_color', 'Text colour when pointed at'],
    ['hover_background', 'background', 'Fill when pointed at'],
] as const) {
    properties.push({
        ...properties.find((item) => item.key === from)!,
        key,
        label,
    });
}

/** Find a property's definition. */
export function definition(property: VisualProperty): PropertyDefinition {
    return properties.find((item) => item.key === property)!;
}

/**
 * Each scale's steps, then the even step it keeps to past the last one.
 * Spacing is Tailwind's classic scale; Tailwind v4 writes any of these as
 * a theme utility (`p-4`, `w-72`).
 */
// Properties that are one thing to the owner when changed together.
const pairs: { keys: [VisualProperty, VisualProperty]; label: string }[] = [
    { keys: ['padding_x', 'padding_y'], label: 'Space inside' },
    { keys: ['margin_x', 'margin_y'], label: 'Space outside' },
    { keys: ['translate_x', 'translate_y'], label: 'Place on the page' },
    // A shape frees the picture's height, as part of the same change.
    { keys: ['aspect_ratio', 'height'], label: 'Picture shape' },
];

// What a change to several properties changed, in a few words: one or two
// things by name, more by the groups they are in.
export function describeChanged(keys: VisualProperty[]): string {
    let names: string[] = [];
    const paired = new Set<VisualProperty>();

    for (const pair of pairs) {
        if (pair.keys.every((key) => keys.includes(key))) {
            pair.keys.forEach((key) => paired.add(key));
            names.push(pair.label);
        }
    }

    names = [
        ...keys
            .filter((key) => !paired.has(key))
            .map((key) => definition(key).label),
        ...names,
    ];

    if (names.length > 2) {
        names = [...new Set(keys.map((key) => definition(key).group))];
    }

    return names.length > 1
        ? `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`
        : (names[0] ?? '');
}

export const scales: Record<Scale, { steps: number[]; every: number }> = {
    spacing: {
        steps: [
            0, 1, 2, 4, 6, 8, 10, 12, 14, 16, 20, 24, 28, 32, 36, 40, 44, 48,
            56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 208, 224, 240, 256,
            288, 320, 384,
        ],
        every: 16,
    },
    border: { steps: [0, 1, 2, 4, 8], every: 4 },
    degrees: { steps: [0], every: 15 },
    percent: { steps: [0], every: 5 },
    count: { steps: [0], every: 1 },
};

// The next step up from a size (zero or more) on a scale.
function above(scale: Scale, size: number): number {
    const { steps, every } = scales[scale];

    return (
        steps.find((step) => step > size) ??
        (Math.floor(size / every) + 1) * every
    );
}

// The next step down from a size above zero.
function below(scale: Scale, size: number): number {
    const { steps, every } = scales[scale];
    const last = steps.at(-1) ?? 0;

    if (size > last) {
        return Math.max(last, (Math.ceil(size / every) - 1) * every);
    }

    return steps.findLast((step) => step < size) ?? 0;
}

/** The step on the scale nearest to a value. */
export function snap(scale: Scale, value: number): number {
    const size = Math.abs(value);
    const { steps, every } = scales[scale];

    // A value already on the scale stays: the steps either side of it are
    // both further away.
    if (
        steps.includes(size) ||
        (size > (steps.at(-1) ?? 0) && size % every === 0)
    ) {
        return value;
    }

    const up = above(scale, size);
    const down = size === 0 ? 0 : below(scale, size);
    const near = size - down <= up - size ? down : up;

    return value < 0 ? -near : near;
}

function clamp(input: PropertyInput, value: number): number {
    if (input.kind !== 'measure') {
        return value;
    }

    const min = input.min ?? (input.allowNegative ? -Infinity : 0);

    return Math.min(input.max ?? Infinity, Math.max(min, value));
}

/**
 * Settle a number the owner typed or dragged to: on the scale, or when
 * fine tuning, any value to two decimals. Words pass through.
 */
export function settle(
    property: VisualProperty,
    value: VisualValue | null,
    fine: boolean,
): VisualValue | null {
    const { input } = definition(property);

    if (typeof value !== 'number' || input.kind !== 'measure') {
        return value;
    }

    const settled = fine
        ? Math.round(value * 100) / 100
        : snap(input.scale, value);

    return clamp(input, settled);
}

/**
 * The value one step up or down from where it is: the next step on the
 * scale, or when fine tuning, one unit (ten with "big").
 */
export function stepFrom(
    property: VisualProperty,
    value: VisualValue | null,
    direction: 1 | -1,
    fine: boolean,
    big = false,
): number {
    const { input } = definition(property);
    const from = typeof value === 'number' ? value : 0;

    if (input.kind !== 'measure') {
        return from;
    }

    let next = from;

    for (let count = big ? 4 : 1; count > 0; count--) {
        if (fine) {
            next += direction * (big ? 2.5 : 1);
        } else if (direction > 0 === next >= 0) {
            // Away from zero.
            const size = above(input.scale, Math.abs(next));
            next = next < 0 || (next === 0 && direction < 0) ? -size : size;
        } else {
            const size = below(input.scale, Math.abs(next));
            next = next < 0 ? -size : size;
        }
    }

    return clamp(input, Math.round(next * 100) / 100);
}

export const radii: Record<string, string> = {
    none: '0',
    xs: '2px',
    sm: '4px',
    md: '6px',
    lg: '8px',
    xl: '12px',
    '2xl': '16px',
    '3xl': '24px',
    '4xl': '32px',
    full: '9999px',
};

const widths: Record<string, string> = {
    full: '100%',
    auto: 'auto',
    fit: 'fit-content',
    screen: '100vw',
    min: 'min-content',
    max: 'max-content',
};

const heights: Record<string, string> = {
    ...widths,
    screen: '100vh',
    svh: '100svh',
    dvh: '100dvh',
};

// Tailwind's defaults, for when the app's CSS leaves a theme variable out
// because nothing used it yet.
const containers: Record<string, string> = {
    xs: '20rem',
    sm: '24rem',
    md: '28rem',
    lg: '32rem',
    xl: '36rem',
    '2xl': '42rem',
    '3xl': '48rem',
    '4xl': '56rem',
    '5xl': '64rem',
    '6xl': '72rem',
    '7xl': '80rem',
};

export const shadows: Record<string, string> = {
    '2xs': '0 1px rgb(0 0 0 / 0.05)',
    xs: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
    sm: '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
    md: '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
    lg: '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
    xl: '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
    '2xl': '0 25px 50px -12px rgb(0 0 0 / 0.25)',
};

const textSizes: Record<string, [string, string]> = {
    xs: ['0.75rem', '1rem'],
    sm: ['0.875rem', '1.25rem'],
    base: ['1rem', '1.5rem'],
    lg: ['1.125rem', '1.75rem'],
    xl: ['1.25rem', '1.75rem'],
    '2xl': ['1.5rem', '2rem'],
    '3xl': ['1.875rem', '2.25rem'],
    '4xl': ['2.25rem', '2.5rem'],
    '5xl': ['3rem', '1'],
    '6xl': ['3.75rem', '1'],
};

// A theme colour, from Tailwind's variable or the theme's own.
const color = (token: VisualValue): string =>
    `var(--color-${token}, var(--${token}))`;

/** Tailwind's letter spacing, in ems, for an app whose theme leaves it
 * out. */
const trackings: Record<string, string> = {
    tighter: '-0.05em',
    tight: '-0.025em',
    normal: '0em',
    wide: '0.025em',
    wider: '0.05em',
    widest: '0.1em',
};

/** Tailwind's line heights, for an app whose theme leaves them out. */
const leadings: Record<string, string> = {
    none: '1',
    tight: '1.25',
    snug: '1.375',
    normal: '1.5',
    relaxed: '1.625',
    loose: '2',
};

export const weights: Record<string, string> = {
    light: '300',
    normal: '400',
    medium: '500',
    semibold: '600',
    bold: '700',
};

const flexPositions: Record<string, string> = {
    start: 'flex-start',
    end: 'flex-end',
    center: 'center',
    stretch: 'stretch',
    baseline: 'baseline',
    between: 'space-between',
    around: 'space-around',
    evenly: 'space-evenly',
};

// The step nearest to a length in pixels, among steps given in rem.
function nearest(
    steps: Record<string, string>,
    pixels: number,
    rem: number,
): string | null {
    let best: string | null = null;

    for (const [step, size] of Object.entries(steps)) {
        const distance = Math.abs(parseFloat(size) * rem - pixels);

        if (
            best === null ||
            distance < Math.abs(parseFloat(steps[best]) * rem - pixels)
        ) {
            best = step;
        }
    }

    return best;
}

/**
 * The step on a scale that is closest to how the part is drawn now, so a
 * slider with nothing chosen starts where the part is.
 */
export function drawnStep(
    property: 'text_size' | 'max_width' | 'line_height' | 'letter_spacing',
    drawn: Drawn | undefined,
): string | null {
    if (drawn === undefined) {
        return null;
    }

    if (property === 'letter_spacing') {
        return drawn.letter_spacing == null
            ? null
            : nearest(trackings, drawn.letter_spacing, 1);
    }

    if (property === 'line_height') {
        return drawn.line_height == null
            ? null
            : nearest(leadings, drawn.line_height, 1);
    }

    if (property === 'text_size') {
        return nearest(
            Object.fromEntries(
                Object.entries(textSizes).map(([step, [size]]) => [step, size]),
            ),
            drawn.text_size,
            drawn.rem,
        );
    }

    if (drawn.max_width === 'none') {
        return 'none';
    }

    if (drawn.max_width === '100%') {
        return 'full';
    }

    return drawn.max_width.endsWith('px')
        ? nearest(containers, parseFloat(drawn.max_width), drawn.rem)
        : null;
}

/**
 * The text size a part is drawn at in pixels, when it falls between the
 * steps, so the owner reads what they see rather than the nearest step.
 */
export function textSizeBetween(drawn: Drawn | undefined): string | null {
    const step = drawnStep('text_size', drawn);

    if (drawn === undefined || step === null) {
        return null;
    }

    const size = parseFloat(textSizes[step][0]) * drawn.rem;

    return Math.abs(size - drawn.text_size) < 0.5
        ? null
        : `${Math.round(drawn.text_size * 10) / 10} px`;
}

const pixels = (value: VisualValue): string =>
    typeof value === 'number' ? `${value}px` : value;

// A length: pixels, a percentage, or a word such as "full".
const length = (value: VisualValue, words: Record<string, string>): string =>
    typeof value === 'number' ? `${value}px` : (words[value] ?? value);

const usable = (value: VisualValue | null | undefined): value is VisualValue =>
    value !== null &&
    value !== undefined &&
    value !== 'mixed' &&
    value !== 'custom';

/**
 * Turn unsaved property values into inline styles, so the preview shows an
 * edit before it is saved. A removed value (null) shows nothing until the
 * preview is rebuilt.
 */
export function inlineStyles(
    changes: Partial<Record<VisualProperty, VisualValue | null>>,
): Record<string, string> {
    const styles: Record<string, string> = {};

    for (const [property, value] of Object.entries(changes)) {
        if (!usable(value)) {
            continue;
        }

        switch (property as VisualProperty) {
            case 'layout':
                styles.display = value === 'hidden' ? 'none' : String(value);
                break;
            case 'direction':
                styles.flexDirection = value === 'down' ? 'column' : 'row';
                break;
            case 'wrap':
                styles.flexWrap = String(value);
                break;
            case 'align':
                styles.alignItems = flexPositions[value] ?? String(value);
                break;
            case 'justify':
                styles.justifyContent = flexPositions[value] ?? String(value);
                break;
            case 'columns':
                styles.gridTemplateColumns = `repeat(${value}, minmax(0, 1fr))`;
                break;
            case 'gap':
                styles.gap = pixels(value);
                break;
            case 'width':
                styles.width = length(value, widths);
                break;
            case 'height':
                styles.height = length(value, heights);
                break;
            case 'rotate':
                styles.rotate = `${value}deg`;
                break;
            // Tailwind v4 moves with the `translate` property and keeps each
            // axis in a variable, so one axis can change on its own.
            case 'translate_x':
            case 'translate_y': {
                const x = changes.translate_x;
                const y = changes.translate_y;

                styles.translate = `${usable(x) ? length(x, { full: '100%' }) : 'var(--tw-translate-x, 0)'} ${usable(y) ? length(y, { full: '100%' }) : 'var(--tw-translate-y, 0)'}`;
                break;
            }
            case 'opacity':
                styles.opacity = String(Number(value) / 100);
                break;
            case 'text_align':
                styles.textAlign = String(value);
                break;
            case 'font_style':
                styles.fontStyle = String(value);
                break;
            case 'text_decoration':
                styles.textDecorationLine = String(value);
                break;
            case 'text_case':
                styles.textTransform = String(value);
                break;
            case 'object_fit':
                styles.objectFit = String(value);
                break;
            // Words past the last line shown end in "…", as
            // Tailwind's line-clamp does.
            case 'line_clamp':
                Object.assign(
                    styles,
                    value === 'none'
                        ? {
                              overflow: 'visible',
                              display: 'block',
                              webkitLineClamp: 'unset',
                          }
                        : {
                              overflow: 'hidden',
                              display: '-webkit-box',
                              webkitBoxOrient: 'vertical',
                              webkitLineClamp: String(value),
                          },
                );
                break;
            case 'object_position':
                styles.objectPosition = String(value);
                break;
            case 'aspect_ratio':
                styles.aspectRatio =
                    value === 'video'
                        ? '16 / 9'
                        : String(value).replace('square', '1');
                break;
            case 'letter_spacing':
                styles.letterSpacing = `var(--tracking-${value}, ${trackings[value] ?? 'normal'})`;
                break;
            case 'line_height':
                styles.lineHeight = `var(--leading-${value}, ${leadings[value] ?? 'normal'})`;
                break;
            case 'padding_x':
                styles.paddingLeft = styles.paddingRight = pixels(value);
                break;
            case 'padding_y':
                styles.paddingTop = styles.paddingBottom = pixels(value);
                break;
            case 'margin_x':
                styles.marginLeft = styles.marginRight = pixels(value);
                break;
            case 'margin_y':
                styles.marginTop = styles.marginBottom = pixels(value);
                break;
            case 'border':
                styles.borderWidth = pixels(value);
                styles.borderStyle ??= 'solid';
                break;
            case 'border_style':
                styles.borderStyle = String(value);
                break;
            case 'radius':
                styles.borderRadius = radii[value] ?? '0';
                break;
            case 'max_width':
                styles.maxWidth =
                    value === 'none'
                        ? 'none'
                        : value === 'full'
                          ? '100%'
                          : value === 'prose'
                            ? '65ch'
                            : `var(--container-${value}, ${containers[value] ?? 'none'})`;
                break;
            case 'shadow':
                styles.boxShadow =
                    value === 'none'
                        ? 'none'
                        : `var(--shadow-${value}, ${shadows[value] ?? 'none'})`;
                break;
            case 'text_size':
                styles.fontSize = `var(--text-${value}, ${textSizes[value]?.[0] ?? 'inherit'})`;
                styles.lineHeight = `var(--text-${value}--line-height, ${textSizes[value]?.[1] ?? 'inherit'})`;
                break;
            case 'text_weight':
                styles.fontWeight = weights[value] ?? String(value);
                break;
            // A colour for when the pointer is on the part shows while it
            // is being chosen; once saved, the app's own class takes over.
            case 'hover_text_color':
            case 'text_color':
                styles.color = color(value);
                break;
            case 'border_color':
                styles.borderColor =
                    value === 'transparent' ? 'transparent' : color(value);
                break;
            case 'hover_background':
            case 'background':
                styles.backgroundColor =
                    value === 'transparent' ? 'transparent' : color(value);
                break;
            case 'fill_color':
                styles.fill =
                    value === 'transparent' ? 'transparent' : color(value);
                break;
            case 'stroke_color':
                styles.stroke =
                    value === 'transparent' ? 'transparent' : color(value);
                break;
        }
    }

    return styles;
}

/**
 * Describe a value in owner words: "16 px", "Half", "not set".
 */
export function describeValue(
    property: PropertyDefinition,
    value: VisualValue | null | undefined,
): string {
    if (value === null || value === undefined) {
        return 'not set';
    }

    if (value === 'mixed') {
        return 'different on each side';
    }

    if (value === 'custom') {
        return property.group === 'Colours'
            ? 'a colour of its own'
            : 'a spacing of its own';
    }

    const { input } = property;
    const words = input.kind === 'choice' ? input.options : input.keywords;
    const word = words?.find((option) => option.value === value);

    if (word !== undefined || input.kind === 'choice') {
        return word?.label ?? String(value);
    }

    return typeof value === 'number'
        ? withUnit(value, input.unit)
        : String(value);
}

/** A number with its unit: "16 px", "45°", "80%", "3 cols". */
export function withUnit(value: number, unit: string): string {
    return unit === '°' || unit === '%'
        ? `${value}${unit}`
        : `${value} ${unit}`;
}
