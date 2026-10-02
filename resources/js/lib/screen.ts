/** Wide enough to show the app at desktop size. */
export const WIDE_SCREEN = '(min-width: 1024px)';

/**
 * Leave whether the screen is wide in a cookie, so the server draws the
 * app at the size the browser will show it.
 */
export function rememberScreen(): void {
    const wide = window.matchMedia(WIDE_SCREEN).matches;

    document.cookie = `screen=${wide ? 'wide' : 'narrow'}; path=/; max-age=31536000; SameSite=Lax`;
}
