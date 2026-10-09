import { nextTick } from 'vue';

let current: ViewTransition | null = null;

// Moves one screen from one layout to another by morphing instead of
// cutting. The browser pictures the screen before and after the change,
// and the parts marked with `data-morph` slide and grow into their new
// places. The marks count only while a morph runs, so going to another
// page still crossfades as a whole.
export function morph(change: () => void): void {
    const still =
        !('startViewTransition' in document) ||
        document.visibilityState === 'hidden' ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (still) {
        change();

        return;
    }

    const root = document.documentElement;
    root.classList.add('morphing');

    const transition = document.startViewTransition(async () => {
        change();
        await nextTick();
    });
    current = transition;

    void transition.finished.finally(() => {
        if (current === transition) {
            root.classList.remove('morphing');
            current = null;
        }
    });
}
