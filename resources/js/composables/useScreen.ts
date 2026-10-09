import { useMediaQuery, useMounted } from '@vueuse/core';
import type { Ref } from 'vue';
import { computed } from 'vue';

/**
 * Whether the screen matches a media query, such as "(min-width: 1024px)".
 *
 * The server cannot see the screen, so it renders as if nothing matched.
 * The browser must start the same way: if it laid out the wide workspace
 * while taking over the server's narrow one, Vue would keep the server's
 * classes and the page would stay half narrow. So this says false until
 * the page is mounted, then follows the screen.
 */
export function useScreen(query: string): Readonly<Ref<boolean>> {
    const mounted = useMounted();
    const matches = useMediaQuery(query);

    return computed(() => mounted.value && matches.value);
}
