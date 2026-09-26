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
];

const radii: Record<string, string> = {
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
        if (value === null || value === undefined || value === 'mixed') {
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
