import type { Device, VisualProperty, VisualValue } from '@/types';

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

export type PropertyInput =
    | { kind: 'choice'; options: { value: VisualValue; label: string }[] }
    | { kind: 'pixels'; allowAuto?: boolean; allowNegative?: boolean }
    | { kind: 'count' };

export type PropertyDefinition = {
    key: VisualProperty;
    label: string;
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
        input: { kind: 'count' },
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
        group: 'Layout',
        when: 'flex-or-grid',
        input: { kind: 'pixels' },
    },
    {
        key: 'width',
        label: 'Width',
        group: 'Size',
        input: {
            kind: 'choice',
            options: [
                { value: 'full', label: 'Fill the space' },
                { value: 'fit', label: 'Fit its contents' },
                { value: 'auto', label: 'Automatic' },
                { value: '75%', label: 'Three quarters' },
                { value: '66.67%', label: 'Two thirds' },
                { value: '50%', label: 'Half' },
                { value: '33.33%', label: 'One third' },
                { value: '25%', label: 'One quarter' },
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
        group: 'Space',
        input: { kind: 'pixels' },
    },
    {
        key: 'padding_y',
        label: 'Space inside, top and bottom',
        group: 'Space',
        input: { kind: 'pixels' },
    },
    {
        key: 'margin_x',
        label: 'Space outside, left and right',
        group: 'Space',
        input: { kind: 'pixels', allowAuto: true, allowNegative: true },
    },
    {
        key: 'margin_y',
        label: 'Space outside, top and bottom',
        group: 'Space',
        input: { kind: 'pixels', allowNegative: true },
    },
    {
        key: 'border',
        label: 'Border',
        group: 'Edges',
        input: { kind: 'pixels' },
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
                { value: 'semibold', label: 'Semi-bold' },
                { value: 'bold', label: 'Bold' },
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
];

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

const pixels = (value: VisualValue): string =>
    typeof value === 'number' ? `${value}px` : value;

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
        if (
            value === null ||
            value === undefined ||
            value === 'mixed' ||
            value === 'custom'
        ) {
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
                styles.width =
                    typeof value === 'number'
                        ? `${value}px`
                        : (widths[value] ?? value);
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
                styles.borderStyle = 'solid';
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
            case 'text_color':
                styles.color = color(value);
                break;
            case 'background':
                styles.backgroundColor =
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
    definition: PropertyDefinition,
    value: VisualValue | null | undefined,
): string {
    if (value === null || value === undefined) {
        return 'not set';
    }

    if (value === 'mixed') {
        return 'different on each side';
    }

    if (value === 'custom') {
        return 'a colour of its own';
    }

    if (definition.input.kind === 'choice') {
        return (
            definition.input.options.find((option) => option.value === value)
                ?.label ?? String(value)
        );
    }

    if (value === 'auto') {
        return 'centred';
    }

    return definition.input.kind === 'pixels' ? `${value} px` : String(value);
}
