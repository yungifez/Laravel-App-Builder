// The app's colours the owner can change in one place, named as the colour
// choices are, the main ones first.
export const themeColors = [
    { token: 'primary', label: 'Main colour' },
    { token: 'primary-foreground', label: 'Text on the main colour' },
    { token: 'background', label: 'Page' },
    { token: 'foreground', label: 'Normal text' },
    { token: 'muted-foreground', label: 'Quiet text' },
    { token: 'secondary', label: 'Second colour' },
    { token: 'accent', label: 'Highlight' },
    { token: 'border', label: 'Edge' },
    { token: 'destructive', label: 'Warning' },
];

// The name of one of the app's colours, or null for one not offered.
export function themeColorLabel(token: string): string | null {
    return themeColors.find((color) => color.token === token)?.label ?? null;
}
