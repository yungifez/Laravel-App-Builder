import { router, usePoll } from '@inertiajs/vue3';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import type { Ref } from 'vue';
import VisualEditController from '@/actions/App/Http/Controllers/VisualEditController';
import { devices, inlineStyles } from '@/lib/visualProperties';
import { show as showPreview } from '@/routes/previews';
import type {
    Device,
    EditorPreview,
    InspectedElement,
    SelectedElement,
    VisualProperty,
    VisualValue,
} from '@/types';

type Source = {
    projectId: () => number;
    preview: () => EditorPreview | null;
    element: () => InspectedElement | null | undefined;
    /** Whether clicking in the app selects a part of it. */
    designing: Ref<boolean>;
};

/**
 * The owner's running app in the workspace: the frame it shows in, the
 * screen size, and the design state (the part the owner pointed at and the
 * unsaved changes to how it looks). The frame and the design panel share it.
 */
export function useAppPreview(source: Source) {
    const frame = ref<HTMLIFrameElement | null>(null);
    const frameSource = ref<string | null>(null);
    const frameKey = ref(0);
    const framePath = ref('/');
    // Start at the owner's own screen size: a phone edits the phone layout.
    const device = ref<Device>(
        typeof window !== 'undefined' &&
            window.matchMedia('(min-width: 1024px)').matches
            ? 'lg'
            : 'base',
    );
    const selected = ref<SelectedElement | null>(null);
    const onlyThisOne = ref(true);
    const changes = reactive<
        Partial<Record<VisualProperty, VisualValue | null>>
    >({});
    const saving = ref(false);
    const saveError = ref<string | null>(null);

    const running = computed(() => source.preview()?.status === 'ready');
    const busy = computed(
        () =>
            source.preview()?.status === 'starting' ||
            source.preview()?.updating === true,
    );
    const frameWidth = computed(
        () =>
            devices.find((option) => option.key === device.value)?.width ??
            null,
    );
    const hasChanges = computed(() => Object.keys(changes).length > 0);

    // The place in the source an edit goes to: the one use of a shared
    // piece, or where the element is written (which changes every use).
    const target = computed(() => {
        if (selected.value === null) {
            return null;
        }

        return onlyThisOne.value && selected.value.instance
            ? { value: selected.value.instance, instance: true }
            : { value: selected.value.source, instance: false };
    });

    function post(message: Record<string, unknown>): void {
        const preview = source.preview();

        if (preview !== null) {
            frame.value?.contentWindow?.postMessage(
                { builder: true, ...message },
                preview.origin,
            );
        }
    }

    function clearChanges(): void {
        for (const key of Object.keys(changes)) {
            delete changes[key as VisualProperty];
        }
    }

    function inspect(): void {
        if (target.value?.value == null) {
            return;
        }

        router.reload({
            only: ['element'],
            data: {
                design: 1,
                target: target.value.value,
                instance: target.value.instance ? 1 : 0,
            },
        });
    }

    function deselect(): void {
        selected.value = null;
        clearChanges();
    }

    function reload(): void {
        const preview = source.preview();

        if (preview !== null) {
            frameSource.value = preview.origin + framePath.value;
            frameKey.value++;
        }
    }

    function onMessage(event: MessageEvent): void {
        const preview = source.preview();

        if (
            preview === null ||
            event.origin !== preview.origin ||
            event.source !== frame.value?.contentWindow ||
            event.data?.builder !== true
        ) {
            return;
        }

        if (event.data.type === 'ready') {
            framePath.value = String(event.data.path ?? '/');
            post({ type: 'mode', editing: source.designing.value });
        }

        if (event.data.type === 'select') {
            selected.value = event.data.element as SelectedElement;
            onlyThisOne.value = true;
            saveError.value = null;
            clearChanges();
            inspect();
        }
    }

    onMounted(() => window.addEventListener('message', onMessage));
    onBeforeUnmount(() => window.removeEventListener('message', onMessage));

    watch(source.designing, (editing) => {
        post({ type: 'mode', editing });

        if (!editing) {
            deselect();
        }
    });

    watch(onlyThisOne, () => {
        clearChanges();
        inspect();
    });

    watch(device, () => clearChanges());

    // Show unsaved values in the app straight away.
    watch(
        () => ({ ...changes }),
        (current) => {
            if (target.value?.value == null) {
                return;
            }

            post({
                type: 'style',
                location: {
                    kind: target.value.instance ? 'instance' : 'source',
                    value: target.value.value,
                },
                styles: inlineStyles(current),
            });
        },
    );

    // Open the app once it is running, and reload it after each rebuild.
    watch(
        () => [source.preview()?.status, source.preview()?.revision] as const,
        ([status], previous) => {
            const preview = source.preview();

            if (status !== 'ready' || preview === null) {
                frameSource.value = null;

                return;
            }

            if (frameSource.value === null) {
                frameSource.value = showPreview(preview.id).url;
            } else if (previous?.[1] !== preview.revision) {
                reload();
                inspect();
            }
        },
        { immediate: true },
    );

    const { start, stop } = usePoll(
        2000,
        { only: ['preview', 'edits'] },
        { autoStart: false },
    );

    watch(busy, (value) => (value ? start() : stop()), { immediate: true });

    function current(property: VisualProperty) {
        return source.element()?.values[device.value][property] ?? null;
    }

    function valueOf(property: VisualProperty): VisualValue | null {
        return property in changes
            ? (changes[property] ?? null)
            : (current(property)?.value ?? null);
    }

    function save(): void {
        const preview = source.preview();
        const element = source.element();

        if (
            preview === null ||
            element == null ||
            target.value?.value == null
        ) {
            return;
        }

        saving.value = true;
        saveError.value = null;

        router.post(
            VisualEditController.store.url(source.projectId()),
            {
                preview: preview.id,
                target: target.value.value,
                instance: target.value.instance,
                revision: element.revision,
                device: device.value,
                changes: { ...changes },
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    clearChanges();
                    inspect();
                },
                onError: (errors) =>
                    (saveError.value = Object.values(errors)[0] ?? null),
                onFinish: () => (saving.value = false),
            },
        );
    }

    return reactive({
        frame,
        frameSource,
        frameKey,
        frameWidth,
        device,
        running,
        selected,
        onlyThisOne,
        changes,
        hasChanges,
        saving,
        saveError,
        target,
        clearChanges,
        deselect,
        reload,
        current,
        valueOf,
        save,
    });
}

export type AppPreviewState = ReturnType<typeof useAppPreview>;
