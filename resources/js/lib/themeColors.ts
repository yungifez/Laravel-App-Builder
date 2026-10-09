import type { AppColor, VisualValue } from '@/types';

// Plain names for the colours of a shadcn theme, which many Laravel apps
// start from, the main ones first. Any other colour keeps its own name.
const known: { name: string; label: string }[] = [
    { name: 'primary', label: 'Main colour' },
    { name: 'primary-foreground', label: 'Text on the main colour' },
    { name: 'background', label: 'Page' },
    { name: 'foreground', label: 'Normal text' },
    { name: 'muted-foreground', label: 'Quiet text' },
    { name: 'secondary', label: 'Second colour' },
    { name: 'accent', label: 'Highlight' },
    { name: 'border', label: 'Edge' },
    { name: 'destructive', label: 'Warning' },
];

// The rest of a shadcn theme: each colours one small corner of the app,
// so the panel leaves them out to keep the main ones easy to find.
const quiet = new Set([
    'card',
    'card-foreground',
    'popover',
    'popover-foreground',
    'secondary-foreground',
    'muted',
    'accent-foreground',
    'destructive-foreground',
    'input',
    'ring',
]);

/** One of the app's colours the owner can change in one place. */
export type ThemeColorChoice = {
    /** The variable that holds the colour. */
    token: string;
    label: string;
};

/** A colour's name in words: "brand-500" is "Brand 500". */
export function colorName(name: string): string {
    const words = name.replace(/[-_]+/g, ' ').trim();

    return (
        known.find((color) => color.name === name)?.label ??
        words.charAt(0).toUpperCase() + words.slice(1)
    );
}

// Whether a colour is one of a shadcn theme's small corners.
const corner = (color: AppColor) =>
    quiet.has(color.name) || /^(chart-|sidebar)/.test(color.name);

/** The app's colours to offer, the known main ones first, then the app's
 * own in the order it writes them. */
export function themeColorChoices(colors: AppColor[]): ThemeColorChoice[] {
    const rank = (color: AppColor) => {
        const at = known.findIndex((item) => item.name === color.name);

        return at === -1 ? known.length : at;
    };

    return colors
        .filter((color) => !corner(color))
        .map((color, order) => ({ color, order }))
        .sort((a, b) => rank(a.color) - rank(b.color) || a.order - b.order)
        .map(({ color }) => ({
            token: color.variable,
            label: colorName(color.name),
        }));
}

/** The colours a part's text, fill or edge can take: the ones the
 * property names for a shadcn theme, when the app has them, then the app's
 * colours from any other design system. */
export function partColorOptions(
    named: { value: VisualValue; label: string }[],
    colors: AppColor[],
): { value: VisualValue; label: string }[] {
    const own = colors.filter((color) => color.classes);
    const names = own.map((color) => color.name);

    return [
        ...named.filter(
            (option) =>
                option.value === 'transparent' ||
                names.includes(String(option.value)),
        ),
        ...own
            .filter(
                (color) =>
                    !corner(color) &&
                    !known.some((item) => item.name === color.name),
            )
            .map((color) => ({
                value: color.name,
                label: colorName(color.name),
            })),
    ];
}

/** The name of one of the app's colours, from the variable that holds it. */
export function themeColorLabel(token: string, colors: AppColor[]): string {
    return colorName(
        colors.find((color) => color.variable === token)?.name ?? token,
    );
}
