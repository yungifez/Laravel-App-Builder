import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * The "Fill the form" button over the app on show. Each page of the app
 * says how many empty fields it has, and puts an example value in each
 * one when asked (resources/preview-tools/fill.js). Nothing is sent: the
 * owner looks, then presses the app's own button.
 *
 * The page answers with counts only. What a field holds never comes here.
 */
export function useFormFill(
    // The frame on show, of the app or of the change the owner tries, and
    // the only address a message from it may come from.
    shown: () => { frame: HTMLIFrameElement | null; origin: string | null },
) {
    const empty = ref(0);

    function post(type: 'fields' | 'fill'): void {
        const { frame, origin } = shown();

        if (origin !== null) {
            frame?.contentWindow?.postMessage({ builder: true, type }, origin);
        }
    }

    function onMessage(event: MessageEvent): void {
        const { frame, origin } = shown();

        if (
            origin === null ||
            event.origin !== origin ||
            event.source !== frame?.contentWindow ||
            event.data?.builder !== true
        ) {
            return;
        }

        if (event.data.type === 'fields') {
            empty.value = Number(event.data.empty) || 0;
        }

        if (event.data.type === 'filled' && Number(event.data.fields) > 0) {
            const fields = Number(event.data.fields);

            toast(
                fields === 1
                    ? 'Filled 1 field with an example'
                    : `Filled ${fields} fields with examples`,
            );
        }
    }

    onMounted(() => {
        window.addEventListener('message', onMessage);
        // The app can open before this page listens, so ask it.
        post('fields');
    });
    onBeforeUnmount(() => window.removeEventListener('message', onMessage));

    // Another frame took the place of the one on show (the app was rebuilt,
    // or the owner looks at a change): its count is not known yet.
    watch(
        () => shown().frame,
        () => {
            empty.value = 0;
            post('fields');
        },
    );

    return { empty, fill: () => post('fill') };
}
