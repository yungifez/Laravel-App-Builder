import { onBeforeUnmount, onMounted, ref } from 'vue';

const KEY = 'builder.panel-width';
const DEFAULT = 384;
const MIN = 320;

// The panel may take up to this share of the window; the app keeps the rest.
const MAX_SHARE = 0.6;

function clamp(width: number): number {
    const max = Math.max(MIN, Math.round(window.innerWidth * MAX_SHARE));

    return Math.min(Math.max(Math.round(width), MIN), max);
}

function stored(): number {
    try {
        const value = Number(window.localStorage.getItem(KEY));

        return Number.isFinite(value) && value > 0 ? clamp(value) : DEFAULT;
    } catch {
        return DEFAULT;
    }
}

function remember(width: number): void {
    try {
        window.localStorage.setItem(KEY, String(width));
    } catch {
        // Without storage the width lasts until the page reloads.
    }
}

/**
 * The width of the side panel, which the owner can drag wider to read a
 * change in full. It is remembered in this browser only.
 */
export function usePanelWidth() {
    // The server cannot read the browser's storage, so the stored width is
    // set once mounted; setting it here would not match the server's page.
    const width = ref(DEFAULT);
    const resizing = ref(false);
    let startX = 0;
    let startWidth = 0;

    function move(event: PointerEvent): void {
        width.value = clamp(startWidth + event.clientX - startX);
    }

    function stop(event?: PointerEvent): void {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', stop);

        if (!resizing.value) {
            return;
        }

        if (event) {
            move(event);
        }

        resizing.value = false;
        remember(width.value);
    }

    function start(event: PointerEvent): void {
        event.preventDefault();
        startX = event.clientX;
        startWidth = width.value;
        resizing.value = true;
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', stop);
    }

    function nudge(event: KeyboardEvent): void {
        const step = event.shiftKey ? 64 : 16;

        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            width.value = clamp(
                width.value + (event.key === 'ArrowRight' ? step : -step),
            );
            remember(width.value);
        }
    }

    function reset(): void {
        width.value = DEFAULT;
        remember(width.value);
    }

    onMounted(() => (width.value = stored()));
    onBeforeUnmount(() => stop());

    return { width, resizing, start, nudge, reset, min: MIN };
}
