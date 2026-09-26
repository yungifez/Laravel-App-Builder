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
import VisualEditReversionController from '@/actions/App/Http/Controllers/VisualEditReversionController';
import VisualMoveController from '@/actions/App/Http/Controllers/VisualMoveController';
import {
    definition,
    devices,
    inlineStyles,
    settle,
    stepFrom,
    withUnit,
} from '@/lib/visualProperties';
import { show as showPreview } from '@/routes/previews';
import type {
    Device,
    EditorPreview,
    InspectedElement,
    SelectedElement,
    VisualEditSummary,
    VisualProperty,
    VisualValue,
} from '@/types';

type Source = {
    projectId: () => number;
    preview: () => EditorPreview | null;
    element: () => InspectedElement | null | undefined;
    edits: () => VisualEditSummary[];
    /** Whether clicking in the app selects a part of it. */
    designing: Ref<boolean>;
};

type Values = Partial<Record<VisualProperty, VisualValue | null>>;

/**
 * Changes to one part on one screen size, with how the part looked when
 * the owner started changing it.
 */
type Batch = {
    target: { value: string; instance: boolean };
    device: Device;
    values: Values;
    classes: string;
    revision: string;
};

/** How long the owner can pause before their changes are saved. */
const SAVE_AFTER_MS = 700;

/** Where the owner's fine tune choice is kept in this browser. */
const FINE_KEY = 'builder.design.fine';

function remembered(key: string): boolean {
    try {
        return window.localStorage.getItem(key) === '1';
    } catch {
        return false;
    }
}

/**
 * The owner's running app in the workspace: the frame it shows in, the
 * screen size, and the design state (the part the owner pointed at and the
 * changes to how it looks). The frame and the design panel share it.
 *
 * Changes save on their own a moment after the owner stops. Each save
 * tells the server the classes the owner expects the part to have, so a
 * save never overwrites what a model or another person changed meanwhile.
 * Saves chain: the next one builds on the commit the last one made, so
 * the owner does not wait for the app to rebuild between changes.
 *
 * Numbers snap to the scale (Tailwind's spacing, 15° turns, 5% steps)
 * unless the owner turns on fine tune; holding Alt while dragging does the
 * opposite of the setting for that drag.
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
    const saveError = ref<string | null>(null);
    // Changes waiting to be saved, oldest first, and the one being saved.
    const queue = ref<Batch[]>([]);
    const sending = ref<Batch | null>(null);
    // Saved changes the running app does not show yet, kept on screen
    // until the rebuilt app does.
    const saved = ref<Batch[]>([]);
    // The newest version of the app and the classes the last save left on
    // its part, so the next save can build on it before the rebuild.
    const head = ref<string | null>(null);
    const last = ref<{ target: string; classes: string } | null>(null);
    // The last part the server said can be edited, shown while the app
    // rebuilds after a save.
    const known = ref<InspectedElement | null>(null);
    // Whether numbers are free instead of snapped to the scale.
    const fine = ref(typeof window !== 'undefined' && remembered(FINE_KEY));
    // How much the app is drawn smaller than it is, so the handles in it
    // stay the same size on screen.
    const zoom = ref(1);
    // Whether the owner is dragging a handle: saving waits until they let go.
    const dragging = ref(false);
    // Whether a part the owner dragged to a new place is being saved.
    const moving = ref(false);
    // Whether the selected part can change places with the part before or
    // after it.
    const neighbours = ref({ earlier: false, later: false });
    // Where the owner had scrolled each page to, so a rebuild keeps it.
    const scrolled = new Map<string, { x: number; y: number }>();
    let timer: ReturnType<typeof setTimeout> | undefined;

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
    const saving = computed(
        () => sending.value !== null || queue.value.length > 0 || moving.value,
    );

    // The part the panel shows: the server's answer, or while the app
    // rebuilds after a save, the last answer that could be edited.
    const element = computed<InspectedElement | null | undefined>(() => {
        const current = source.element();

        if (
            current?.reason === 'updating' &&
            known.value !== null &&
            known.value.target === current.target
        ) {
            return known.value;
        }

        return current;
    });

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

    function here(batch: Batch | null | undefined): batch is Batch {
        return (
            batch != null &&
            batch.target.value === target.value?.value &&
            batch.device === device.value
        );
    }

    // Everything the owner changed on this part that the app does not show
    // yet, oldest first so the newest value wins.
    function unshown(): Values {
        return Object.assign(
            {},
            ...[...saved.value, sending.value, ...queue.value]
                .filter(here)
                .map((batch) => batch.values),
        );
    }

    function schedule(): void {
        clearTimeout(timer);
        timer = setTimeout(save, SAVE_AFTER_MS);
    }

    function change(
        property: VisualProperty,
        value: VisualValue | null,
        free: boolean = fine.value,
    ): void {
        const base = element.value;
        const newest = queue.value.at(-1);
        const where = target.value;

        if (where?.value == null || !base?.editable) {
            return;
        }

        value = settle(property, value, free);

        if (here(newest)) {
            newest.values[property] = value;
        } else {
            queue.value.push({
                target: { value: where.value, instance: where.instance },
                device: device.value,
                values: { [property]: value },
                classes: base.classes,
                revision: base.revision,
            });
        }

        saveError.value = null;
        showUnshown();

        if (!dragging.value) {
            schedule();
        }
    }

    // Hold saving while the owner drags, and save once they let go.
    function hold(on: boolean): void {
        dragging.value = on;

        if (!on) {
            schedule();
        }
    }

    // One step up or down, on the scale or by one unit when fine tuning.
    function nudge(
        property: VisualProperty,
        direction: 1 | -1,
        big = false,
    ): void {
        change(
            property,
            stepFrom(property, valueOf(property), direction, fine.value, big),
            true,
        );
    }

    // A drag on a handle in the app: the values it points at, raw. The
    // label next to the part says where each one lands.
    function adjust(
        values: Partial<Record<VisualProperty, number>>,
        phase: 'move' | 'end',
        invert: boolean,
    ): void {
        dragging.value = phase === 'move';

        for (const [property, value] of Object.entries(values)) {
            change(property as VisualProperty, value, fine.value !== invert);
        }

        post({
            type: 'hint',
            text:
                phase === 'end'
                    ? null
                    : Object.keys(values)
                          .map((property) => {
                              const { short, label, input } = definition(
                                  property as VisualProperty,
                              );
                              const value = valueOf(property as VisualProperty);

                              return `${short ?? label} ${typeof value === 'number' && input.kind === 'measure' ? withUnit(value, input.unit) : (value ?? '')}`;
                          })
                          .join(' · '),
        });

        if (phase === 'end') {
            schedule();
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
        save();
        selected.value = null;
        known.value = null;
        neighbours.value = { earlier: false, later: false };
        post({ type: 'clear' });
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

        const data = event.data;

        if (data.type === 'ready') {
            framePath.value = String(data.path ?? '/');
            post({ type: 'mode', editing: source.designing.value });
            post({ type: 'zoom', zoom: zoom.value });
            post({ type: 'scroll', to: scrolled.get(framePath.value) ?? null });

            // A rebuild reloads the app: pick the same part again.
            if (target.value?.value != null && source.designing.value) {
                post({ type: 'pick', location: location() });
                post({ type: 'handles', enabled: editable.value });
            }

            showUnshown();
        }

        if (data.type === 'scrolled') {
            scrolled.set(String(data.path ?? '/'), {
                x: Number(data.x) || 0,
                y: Number(data.y) || 0,
            });
        }

        if (data.type === 'select') {
            save();
            selected.value = data.element as SelectedElement;
            onlyThisOne.value = true;
            saveError.value = null;
            known.value = null;
            inspect();
        }

        if (data.type === 'adjust' && data.values) {
            adjust(
                data.values,
                data.phase === 'end' ? 'end' : 'move',
                Boolean(data.alt),
            );
        }

        if (data.type === 'nudge' && editable.value) {
            for (const [property, direction] of Object.entries(
                (data.steps ?? {}) as Record<string, number>,
            )) {
                nudge(
                    property as VisualProperty,
                    direction > 0 ? 1 : -1,
                    Boolean(data.big),
                );
            }
        }

        if (data.type === 'neighbours') {
            neighbours.value = {
                earlier: data.earlier === true,
                later: data.later === true,
            };
        }

        if (data.type === 'move' && data.to) {
            move(
                data.to as SelectedElement,
                data.placement === 'before' ? 'before' : 'after',
            );
        }

        // Keys pressed while the app has focus.
        if (data.type === 'key') {
            if (data.key === 'escape') {
                deselect();
            } else if (data.key === 'undo' || data.key === 'redo') {
                const edit =
                    data.key === 'redo' ? redoable.value : undoable.value;

                if (edit !== undefined) {
                    step(edit);
                }
            }
        }
    }

    // Where the selected part is written, as the app's markers name it.
    function location(): { kind: string; value: string } | null {
        return target.value?.value == null
            ? null
            : {
                  kind: target.value.instance ? 'instance' : 'source',
                  value: target.value.value,
              };
    }

    // Move the selected part one place earlier or later on the page.
    function shift(direction: -1 | 1): void {
        if (!saving.value) {
            post({ type: 'shift', direction });
        }
    }

    // Select the part around the selected one, or the first part inside it.
    function pickNear(direction: 'parent' | 'child'): void {
        post({ type: 'pick', direction });
    }

    const editable = computed(() => element.value?.editable === true);

    onMounted(() => window.addEventListener('message', onMessage));
    onBeforeUnmount(() => window.removeEventListener('message', onMessage));

    watch(source.designing, (editing) => {
        post({ type: 'mode', editing });

        if (!editing) {
            deselect();
        }
    });

    watch(onlyThisOne, () => {
        save();
        known.value = null;
        inspect();
    });

    watch(device, () => save());

    watch(fine, (value) => {
        try {
            window.localStorage.setItem(FINE_KEY, value ? '1' : '0');
        } catch {
            // Not kept: the choice lasts until the page closes.
        }
    });

    watch(zoom, (value) => post({ type: 'zoom', zoom: value }));

    // Handles only show on a part that can be changed in place.
    watch(editable, (enabled) => post({ type: 'handles', enabled }));

    // Show changes in the app straight away, before they are saved.
    function showUnshown(): void {
        if (target.value?.value == null) {
            return;
        }

        post({
            type: 'style',
            location: location(),
            styles: inlineStyles(unshown()),
        });
    }

    // Remember the part while it can be edited. Once the rebuilt app shows
    // every saved change, the kept copy of them is no longer needed.
    watch(
        () => source.element(),
        (current) => {
            if (current == null || !current.editable) {
                return;
            }

            known.value = current;

            if (head.value === null || current.revision === head.value) {
                head.value = current.revision;
                saved.value = [];
                last.value = null;
            }
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
        return element.value?.values[device.value][property] ?? null;
    }

    function valueOf(property: VisualProperty): VisualValue | null {
        const shown = unshown();

        return property in shown
            ? (shown[property] ?? null)
            : (current(property)?.value ?? null);
    }

    function failed(message: string | null): void {
        saveError.value = message;
        queue.value = [];
        saved.value = [];
        last.value = null;
        head.value = null;
        known.value = null;
        showUnshown();
        inspect();
    }

    // Save the oldest waiting changes now. One save runs at a time; the
    // rest wait for it. A save builds on the last one: the commit it made
    // and the classes it left, or else the part as the server last showed
    // it.
    function save(): void {
        clearTimeout(timer);

        const preview = source.preview();
        const batch = queue.value[0];

        if (sending.value !== null || preview === null || batch === undefined) {
            return;
        }

        queue.value.shift();
        sending.value = batch;

        const fresh =
            known.value?.target === batch.target.value ? known.value : null;
        const expected =
            last.value?.target === batch.target.value
                ? last.value.classes
                : (fresh?.classes ?? batch.classes);
        const newest = source.edits()[0]?.id ?? 0;

        router.post(
            VisualEditController.store.url(source.projectId()),
            {
                preview: preview.id,
                target: batch.target.value,
                instance: batch.target.instance,
                revision: head.value ?? fresh?.revision ?? batch.revision,
                expected,
                device: batch.device,
                changes: batch.values,
            },
            {
                only: ['edits', 'preview'],
                // Other visits, such as looking at the part again, must
                // not cancel a save.
                async: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    const edit = source.edits()[0];

                    if (edit === undefined || edit.id <= newest) {
                        return;
                    }

                    head.value = edit.revision;
                    last.value = {
                        target: batch.target.value,
                        classes: edit.classes,
                    };
                    saved.value.push(batch);
                },
                onError: (errors) => failed(Object.values(errors)[0] ?? null),
                onFinish: () => {
                    sending.value = null;

                    if (queue.value.length > 0) {
                        schedule();
                    }
                },
            },
        );
    }

    // Put the selected part just before or after another part next to it,
    // where the owner dropped it. Changes waiting to be saved go first, so
    // the move builds on them.
    function move(to: SelectedElement, placement: 'before' | 'after'): void {
        const preview = source.preview();
        // A part moves where it is placed on the page: the one use of a
        // shared piece, as the preview found its neighbours.
        const from = selected.value?.instance ?? selected.value?.source;

        if (preview === null || !from || moving.value) {
            return;
        }

        if (sending.value !== null || queue.value.length > 0) {
            save();
            setTimeout(() => move(to, placement), 200);

            return;
        }

        moving.value = true;
        saveError.value = null;

        router.post(
            VisualMoveController.store.url(source.projectId()),
            {
                preview: preview.id,
                target: from,
                instance: Boolean(selected.value?.instance),
                to: to.instance ?? to.source,
                to_instance: Boolean(to.instance),
                placement,
                revision: head.value ?? element.value?.revision,
            },
            {
                only: ['edits', 'preview'],
                async: true,
                preserveScroll: true,
                preserveState: true,
                onFlash: (flash) => {
                    const moved = flash.moved as
                        | { target: string; instance: boolean }
                        | undefined;

                    if (moved !== undefined && selected.value !== null) {
                        onlyThisOne.value = true;
                        selected.value = moved.instance
                            ? { ...selected.value, instance: moved.target }
                            : { ...selected.value, source: moved.target };
                    }
                },
                onSuccess: () => {
                    saved.value = [];
                    last.value = null;
                    head.value = null;
                    known.value = null;
                    inspect();
                },
                onError: (errors) =>
                    (saveError.value = Object.values(errors)[0] ?? null),
                onFinish: () => (moving.value = false),
            },
        );
    }

    // Undo takes back the newest change still in place; redo puts back the
    // one undone just before it, like any editor.
    const undoable = computed(() =>
        source.edits().find((edit) => edit.reverted_at === null),
    );
    const redoable = computed(() => {
        const edits = source.edits();
        const index = edits.findIndex((edit) => edit.reverted_at === null);
        const edit = index === -1 ? edits.at(-1) : edits[index - 1];

        return edit?.reverted_at ? edit : undefined;
    });

    // Undo or redo one saved change. The server refuses when the part was
    // changed since, so nothing anyone else did is lost.
    function step(edit: VisualEditSummary): void {
        if (saving.value) {
            return;
        }

        saveError.value = null;

        const options = {
            only: ['edits', 'preview'],
            async: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                saved.value = [];
                last.value = null;
                head.value = null;
                known.value = null;
                inspect();
            },
            onError: (errors: Record<string, string>) =>
                (saveError.value = Object.values(errors)[0] ?? null),
        };

        if (edit.reverted_at === null) {
            router.post(
                VisualEditReversionController.store.url(edit.id),
                {},
                options,
            );
        } else {
            router.delete(
                VisualEditReversionController.destroy.url(edit.id),
                options,
            );
        }
    }

    function onKey(event: KeyboardEvent): void {
        const field = (event.target as HTMLElement | null)?.closest(
            'input, textarea, select, [contenteditable]',
        );

        if (
            !source.designing.value ||
            field ||
            !(event.metaKey || event.ctrlKey) ||
            event.key.toLowerCase() !== 'z'
        ) {
            return;
        }

        const edit = event.shiftKey ? redoable.value : undoable.value;

        if (edit !== undefined) {
            event.preventDefault();
            step(edit);
        }
    }

    onMounted(() => window.addEventListener('keydown', onKey));
    onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

    return reactive({
        frame,
        frameSource,
        frameKey,
        frameWidth,
        device,
        running,
        selected,
        onlyThisOne,
        element,
        saving,
        saveError,
        target,
        change,
        nudge,
        hold,
        fine,
        zoom,
        dragging,
        pickNear,
        neighbours,
        shift,
        deselect,
        reload,
        current,
        valueOf,
        save,
        step,
        undoable,
        redoable,
    });
}

export type AppPreviewState = ReturnType<typeof useAppPreview>;
