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
import NewPartController from '@/actions/App/Http/Controllers/NewPartController';
import VisualEditController from '@/actions/App/Http/Controllers/VisualEditController';
import VisualEditReversionController from '@/actions/App/Http/Controllers/VisualEditReversionController';
import ThemeColorController from '@/actions/App/Http/Controllers/ThemeColorController';
import VisualLinkController from '@/actions/App/Http/Controllers/VisualLinkController';
import VisualPictureController from '@/actions/App/Http/Controllers/VisualPictureController';
import VisualMoveController from '@/actions/App/Http/Controllers/VisualMoveController';
import VisualPartController from '@/actions/App/Http/Controllers/VisualPartController';
import VisualTextController from '@/actions/App/Http/Controllers/VisualTextController';
import {
    definition,
    devices,
    inlineStyles,
    settle,
    stepFrom,
    withUnit,
} from '@/lib/visualProperties';
import { newParts } from '@/lib/partKinds';
import type { NewPartKind } from '@/lib/partKinds';
import { show as showPreview } from '@/routes/previews';
import type {
    AppColor,
    Device,
    EditorPreview,
    InspectedElement,
    PagePart,
    SelectedElement,
    VisualEditSummary,
    VisualProperty,
    VisualValue,
} from '@/types';

type Source = {
    projectId: () => string;
    preview: () => EditorPreview | null;
    element: () => InspectedElement | null | undefined;
    edits: () => VisualEditSummary[];
    /** The colours the app's stylesheets write, as its design system
     * names them. */
    colors: () => AppColor[];
    /** Whether clicking in the app selects a part of it. */
    designing: Ref<boolean>;
};

type Values = Partial<Record<VisualProperty, VisualValue | null>>;

/**
 * Changes to one part on one screen size, with how the part looked when
 * the owner started changing it.
 */
type Batch = {
    /** Where the part is written; "instance" is null when not known. */
    target: { value: string; instance: boolean | null };
    device: Device;
    values: Values;
    classes: string;
    revision: string;
    /** The classes the part has after an undo or redo. */
    shows?: string;
};

type Step = 'undo' | 'redo';

// A part the app took out, and where it was: after the part before it, or
// first in the part around it.
// What the builder does to the picked part as a whole.
type Reshape = 'duplicate' | 'remove' | 'add';

type Spot = {
    html: string;
    after: { kind: string; value: string } | null;
    inside: { kind: string; value: string } | null;
};

/** Which way a part lies on screen from another. */
export type Way = 'up' | 'down' | 'left' | 'right';

const ways: unknown[] = ['up', 'down', 'left', 'right'];

/** How long the owner can pause before their changes are saved. */
const SAVE_AFTER_MS = 700;

/** Where the owner's fine tune choice is kept in this browser. */
const FINE_KEY = 'builder.design.fine';

/** Where the owner's choice to move parts freely is kept in this browser. */
const FREE_KEY = 'builder.design.free';

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
    // The app's frames. The first is on show. After a rebuild, the new app
    // loads hidden behind it and takes its place once drawn, so the app
    // never goes blank and nothing the owner is doing is cut off. "shows"
    // counts the saved changes the new app includes.
    const frames = ref<
        { key: number; src: string; shows: number; revision: string | null }[]
    >([]);

    // The app shows only once it has drawn, so opening it never flashes a
    // blank page. A page that never says so still shows after a while.
    const drawnKey = ref<number | null>(null);
    const drawn = computed(
        () =>
            frames.value[0] !== undefined &&
            drawnKey.value === frames.value[0].key,
    );

    watch(
        () => frames.value[0]?.key,
        (key) =>
            key !== undefined &&
            setTimeout(() => {
                if (frames.value[0]?.key === key) {
                    drawnKey.value = key;
                }
            }, 4000),
    );
    const elements = new Map<number, HTMLIFrameElement>();
    let frameKeys = 0;
    // Whether the next frame has drawn, and whether the owner is dragging
    // a part to a new place in the app.
    const nextDrawn = ref(false);
    const holding = ref(false);
    const framePath = ref('/');

    // The page of the app the owner was on, kept in this browser, so the
    // app opens there again after a reload or a restart, not on its front
    // page. Storage can be missing or refused; the front page is then used.
    function savedPath(): string {
        try {
            return (
                localStorage.getItem(
                    `builder:app-path:${source.projectId()}`,
                ) ?? '/'
            );
        } catch {
            return '/';
        }
    }

    function savePath(path: string): void {
        try {
            localStorage.setItem(
                `builder:app-path:${source.projectId()}`,
                path,
            );
        } catch {
            // Only a convenience.
        }
    }
    // The pages the owner has been to, oldest first, and the one on show,
    // so Back and Forward move through them as a browser does.
    const visited = ref<string[]>([]);
    const visitedAt = ref(-1);
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
    // A part in a row or a grid keeps to its places when moved, unless the
    // owner chose to place parts anywhere.
    const moveFreely = ref(
        typeof window !== 'undefined' && remembered(FREE_KEY),
    );
    // The app's own theme colours as it draws them, by token, so a swatch
    // shows the colour the app will really get.
    const theme = ref<Record<string, string>>({});
    // Whether the app shows its dark look, whose colours are written apart.
    const themeDark = ref(false);
    // Whether the app has a dark look at all, and the look the owner chose
    // to show instead of the one the device prefers.
    const themeLooks = ref(false);
    const look = ref<'light' | 'dark' | null>(null);
    // Theme colours chosen but not yet built into the app on show, by
    // token, so a newer frame shows them too; with the version that has
    // them once saved. A colour shown by an undo or redo names its change
    // and the version the change was at, since the version that has it is
    // known only once the step is saved.
    const recolored = new Map<
        string,
        {
            value: string;
            revision: string | null;
            step?: { edit: string; from: string };
        }
    >();
    // The parts on the page on show, in page order, for the parts list.
    const parts = ref<PagePart[]>([]);
    // How much the app is drawn smaller than it is, so the handles in it
    // stay the same size on screen.
    const zoom = ref(1);
    // Whether the owner is dragging a handle: saving waits until they let go.
    const dragging = ref(false);
    // Whether a part the owner dragged to a new place is being saved.
    const moving = ref(false);
    // Whether the selected part can change places with the part before or
    // after it.
    // Which way the parts next to the selected one lie on screen, so moving
    // it can be named the way the owner sees it.
    const neighbours = ref<{ earlier: Way | null; later: Way | null }>({
        earlier: null,
        later: null,
    });
    // Undo and redo the server has not done yet, oldest first. The edit is
    // null while the change it takes back is still being saved.
    const steps = ref<{ key: Step; edit: VisualEditSummary | null }[]>([]);
    // Edits undone or redone in the app ahead of the server, by id, with
    // how the app shows them meanwhile.
    const claims = ref(
        new Map<string, { undone: boolean; shown: Batch | null }>(),
    );
    const stepping = ref(false);
    // Changes taken back before they were saved, for redo.
    const undone = ref<Batch[]>([]);
    // Where a part the owner moved is written now. The running app still
    // names its old place until it is rebuilt.
    const movedTo = ref<SelectedElement | null>(null);
    // The version of the app the part was moved or copied in; the new
    // place is picked once a newer version is on show.
    let movedFrom: string | null = null;
    // Which versions of the app the page on show holds, as undo and redo
    // left it: null while it holds the version it was built from.
    let alike: Set<string | null> | null = null;
    // A picture the owner chose, shown from the file itself until the app
    // built with it is on show: an app built before it would show the old
    // one. The version it is saved in is known once it is saved.
    let chosen: {
        location: { kind: string; value: string };
        picture: File;
        revision: string | null;
    } | null = null;
    // Words the owner wrote in a new part before it was saved, and where
    // the new part is once it is: they are kept when the app shows it.
    let fresh: { text: string; before: string; at: string | null } | null =
        null;
    // Parts the app took out, by the edit that took them, so undoing a
    // removal or redoing a copy puts them back at once. Removals not
    // saved yet wait in order for theirs; a null one cannot be put back.
    const taken = new Map<string, Spot>();
    const taking: { spot: Spot | null }[] = [];
    // How many places each saved move took its part among the parts beside
    // it, by edit, so undoing it moves the part back at once.
    const shifted = new Map<string, number>();
    // A picture file dropped on a picture in the app, waiting for that
    // picture's details.
    let dropped: File | null = null;
    // Where the owner had scrolled each page to, so a rebuild keeps it.
    const scrolled = new Map<string, { x: number; y: number }>();
    let timer: ReturnType<typeof setTimeout> | undefined;

    const running = computed(() => source.preview()?.status === 'ready');
    // The app stopped under a preview still marked as running, as when the
    // machine restarts. The owner starts it again.
    const lost = ref(false);
    watch(
        () => source.preview()?.id,
        () => (lost.value = false),
    );
    // The running app does not have the latest saved version yet.
    const updating = computed(() => source.preview()?.updating === true);
    // The running app was rebuilt with the latest saved version.
    const upToDate = computed(
        () =>
            source.preview()?.status === 'ready' &&
            source.preview()?.error == null &&
            !updating.value,
    );
    const busy = computed(
        () => source.preview()?.status === 'starting' || updating.value,
    );
    const frameWidth = computed(
        () =>
            devices.find((option) => option.key === device.value)?.width ??
            null,
    );
    const saving = computed(
        () =>
            sending.value !== null ||
            queue.value.length > 0 ||
            moving.value ||
            stepping.value,
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

    function post(
        message: Record<string, unknown>,
        to: Window | null | undefined = frame.value?.contentWindow,
    ): void {
        const preview = source.preview();

        if (preview !== null) {
            to?.postMessage({ builder: true, ...message }, preview.origin);
        }
    }

    // Show the space around the selected part while the owner works with it.
    function showSpacing(on: boolean): void {
        post({ type: 'spacing', on });
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
        undone.value = [];
        showUnshown();

        if (!dragging.value) {
            schedule();
        }
    }

    // How a part looks, copied to give to other parts, as in design tools.
    // Where a part sits and how big it is stay its own.
    const lookProperties: VisualProperty[] = [
        'padding_x',
        'padding_y',
        'border',
        'border_style',
        'radius',
        'shadow',
        'opacity',
        'text_size',
        'text_weight',
        'text_align',
        'font_style',
        'text_decoration',
        'text_case',
        'object_fit',
        'object_position',
        'line_height',
        'letter_spacing',
        'text_color',
        'border_color',
        'background',
        'hover_text_color',
        'hover_background',
        'fill_color',
        'stroke_color',
    ];
    const copiedLook = ref<Partial<
        Record<VisualProperty, VisualValue | null>
    > | null>(null);

    function copyLook(): void {
        if (element.value === null) {
            return;
        }

        // A colour of the part's own, or sides that differ, cannot be
        // written onto another part; those stay as the other part has them.
        copiedLook.value = Object.fromEntries(
            lookProperties
                .map((property) => [property, valueOf(property)] as const)
                .filter(([, value]) => value !== 'custom' && value !== 'mixed'),
        );
    }

    // Give the picked part the look copied. It is saved as one change.
    function pasteLook(): void {
        const look = copiedLook.value;

        if (look === null || !element.value?.editable) {
            return;
        }

        for (const property of lookProperties) {
            if (!(property in look)) {
                continue;
            }

            const value = look[property] ?? null;

            if (value !== valueOf(property)) {
                change(property, value, true);
            }
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
        neighbours.value = { earlier: null, later: null };
        post({ type: 'clear' });
        post({ type: 'outline' });
    }

    function bind(key: number, element: HTMLIFrameElement | null): void {
        if (element === null) {
            elements.delete(key);
        } else {
            elements.set(key, element);
        }

        frame.value = elements.get(frames.value[0]?.key ?? -1) ?? null;
    }

    function windowOf(key: number | undefined): Window | null {
        return key === undefined
            ? null
            : (elements.get(key)?.contentWindow ?? null);
    }

    // Load the app again behind the one on show.
    function reload(): void {
        const preview = source.preview();

        if (preview === null) {
            return;
        }

        const next = {
            key: ++frameKeys,
            src: preview.origin + framePath.value,
            shows: preview.updating ? 0 : saved.value.length,
            revision: preview.updating ? null : preview.revision,
        };

        frames.value =
            frames.value.length === 0 ? [next] : [frames.value[0], next];
        nextDrawn.value = false;

        // A page that never says it has drawn still takes over in the end.
        setTimeout(() => {
            if (frames.value[1]?.key === next.key) {
                nextDrawn.value = true;
                swap();
            }
        }, 15000);
    }

    function takeNeighbours(data: Record<string, unknown>): void {
        neighbours.value = {
            earlier: ways.includes(data.earlier) ? (data.earlier as Way) : null,
            later: ways.includes(data.later) ? (data.later as Way) : null,
        };
    }

    // The variables that hold the app's colours, for the app to say how it
    // draws each one now.
    const colors = computed(() => source.colors());
    const colorVariables = () => colors.value.map((color) => color.variable);

    // The colours arrive after the page, so the app is asked again.
    watch(colors, () => post({ type: 'theme', tokens: colorVariables() }));

    // Tell a frame's app how to show: designing or not, the scroll, the
    // picked part and the changes it does not show yet.
    function setUp(to: Window | null): void {
        post({ type: 'mode', editing: source.designing.value }, to);

        if (look.value !== null) {
            post({ type: 'look', dark: look.value === 'dark' }, to);
        }

        post({ type: 'theme', tokens: colorVariables() }, to);

        for (const [token, { value }] of recolored) {
            post({ type: 'recolor', token, value }, to);
        }
        post({ type: 'zoom', zoom: zoom.value }, to);
        post({ type: 'scroll', to: scrolled.get(framePath.value) ?? null }, to);

        if (target.value?.value != null && source.designing.value) {
            post({ type: 'pick', location: location() }, to);
            post({ type: 'handles', enabled: editable.value }, to);
        }

        post({ type: 'free', enabled: moveFreely.value }, to);

        showUnshown(to);

        if (source.designing.value) {
            post({ type: 'outline' }, to);
        }
    }

    // Show the next frame in place of the current one, once it has drawn
    // and the owner is not dragging.
    function swap(): void {
        const next = frames.value[1];
        const to = windowOf(next?.key);

        if (
            !next ||
            !to ||
            !nextDrawn.value ||
            dragging.value ||
            holding.value
        ) {
            return;
        }

        // Undo or redo showed newer versions at once. An app built before
        // them would take them away for a moment while a newer one is
        // coming, so it is skipped.
        if (
            alike !== null &&
            alike.size > 0 &&
            next.revision !== null &&
            !holds(next.revision) &&
            (stepping.value ||
                steps.value.length > 0 ||
                source.preview()?.updating === true)
        ) {
            return;
        }

        // The new app shows these saved changes itself, and names a moved
        // part by its new place.
        saved.value = saved.value.slice(next.shows);

        const moved =
            movedTo.value !== null &&
            next.revision !== null &&
            next.revision !== movedFrom;

        if (moved) {
            selected.value = movedTo.value;
            movedTo.value = null;
        }

        setUp(to);

        // A chosen picture shows in an app built before it was saved; the
        // app built with it shows it itself.
        if (chosen !== null && next.revision === chosen.revision) {
            chosen = null;
        } else if (chosen !== null) {
            post(
                {
                    type: 'picture',
                    location: chosen.location,
                    picture: chosen.picture,
                },
                to,
            );
        }

        // Words written in a new part show in the new app before it does.
        const part = selected.value;

        if (
            moved &&
            fresh !== null &&
            fresh.text !== '' &&
            part !== null &&
            (part.instance ?? part.source) === fresh.at
        ) {
            post({ type: 'words', text: fresh.text }, to);
        }

        // Give the app a moment to apply them before it shows.
        setTimeout(() => {
            if (frames.value[1]?.key === next.key) {
                // Keys the owner was pressing in the old app go on to the
                // new one.
                const focused =
                    document.activeElement !== null &&
                    document.activeElement === frame.value;

                frames.value = [next];
                drawnKey.value = next.key;
                alike = null;
                inspect();

                // It listed its parts before it showed; ask again now.
                if (source.designing.value) {
                    post({ type: 'outline' }, windowOf(next.key));
                }

                // A part picked by its new place (a copy, a new part or a
                // moved part) is told back now the app is on show, so the
                // panel names it as it is.
                if (moved && location() !== null) {
                    post(
                        { type: 'pick', location: location(), tell: true },
                        windowOf(next.key),
                    );
                }

                if (focused) {
                    elements.get(next.key)?.focus();
                }
            }
        }, 50);
    }

    watch(
        [dragging, holding, stepping, () => source.preview()?.updating],
        swap,
    );

    function onMessage(event: MessageEvent): void {
        const preview = source.preview();

        if (
            preview === null ||
            event.origin !== preview.origin ||
            event.data?.builder !== true
        ) {
            return;
        }

        const data = event.data;

        if (
            data.type === 'lost' &&
            frames.value.some((item) => windowOf(item.key) === event.source)
        ) {
            lost.value = true;

            return;
        }

        // The next frame only says when it has drawn, and what is next to
        // the part picked in it: it shows the picked part at its new place.
        if (
            event.source !== null &&
            event.source === windowOf(frames.value[1]?.key)
        ) {
            if (data.type === 'neighbours') {
                takeNeighbours(data);
            }

            if (data.type === 'drawn') {
                nextDrawn.value = true;
                swap();
            }

            return;
        }

        if (event.source !== frame.value?.contentWindow) {
            return;
        }

        if (data.type === 'follow' && typeof data.href === 'string') {
            follow(data.href);

            return;
        }

        if (data.type === 'ready' || data.type === 'page') {
            framePath.value = String(data.path ?? '/');
            savePath(framePath.value);

            // A new page drops the pages ahead, as a browser does. Back and
            // Forward move first, so the page they open is already there.
            if (visited.value[visitedAt.value] !== framePath.value) {
                visited.value = [
                    ...visited.value.slice(0, visitedAt.value + 1),
                    framePath.value,
                ].slice(-20);
                visitedAt.value = visited.value.length - 1;
            }
        }

        if (data.type === 'ready') {
            holding.value = false;
            setUp(frame.value?.contentWindow);
        }

        // The page may draw its parts after it is ready.
        if (data.type === 'drawn') {
            drawnKey.value = frames.value[0]?.key ?? null;
            setUp(frame.value?.contentWindow);
        }

        if (data.type === 'outline' && Array.isArray(data.parts)) {
            parts.value = data.parts as PagePart[];
        }

        if (data.type === 'theme' && typeof data.colors === 'object') {
            theme.value = data.colors as Record<string, string>;
            themeDark.value = data.dark === true;
            themeLooks.value = data.looks === true;
        }

        if (data.type === 'holding') {
            holding.value = data.on === true;
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

            const part = selected.value;

            if (
                fresh !== null &&
                fresh.at !== null &&
                (part.instance ?? part.source) === fresh.at
            ) {
                reword(fresh.text, fresh.before);
                fresh = null;
            }
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
            takeNeighbours(data);
        }

        // A picture file the owner dropped on a picture in the app: it goes
        // in once the builder knows whether that picture can change here.
        if (data.type === 'dropped' && data.picture instanceof Blob) {
            dropped = data.picture as File;
        }

        if (data.type === 'taken') {
            const spot = (data.spot ?? null) as Spot | null;

            if (typeof data.edit === 'string') {
                taken.set(data.edit, spot as Spot);
            } else if (taking.length > 0) {
                (taking.shift() as { spot: Spot | null }).spot = spot;
            }
        }

        if (data.type === 'words' && typeof data.text === 'string') {
            if (data.fresh === true) {
                fresh = {
                    text: data.text,
                    before: String(data.before ?? ''),
                    at: fresh?.at ?? null,
                };
            } else {
                reword(data.text, String(data.before ?? ''));
            }
        }

        if (data.type === 'move' && data.to) {
            move(
                data.to as SelectedElement,
                data.placement === 'before' ? 'before' : 'after',
                typeof data.by === 'number' ? data.by : null,
            );
        }

        // Keys pressed while the app has focus.
        if (data.type === 'key') {
            if (data.key === 'escape') {
                deselect();
            } else if (data.key === 'undo' || data.key === 'redo') {
                press(data.key);
            } else if (data.key === 'hide') {
                hide();
            } else if (data.key === 'duplicate') {
                reshape('duplicate');
            } else if (data.key === 'copy-look') {
                copyLook();
            } else if (data.key === 'paste-look') {
                pasteLook();
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

    // Move the selected part one place earlier or later on the page. The
    // app moves it at once; saving waits for the changes before it.
    function shift(direction: -1 | 1): void {
        post({ type: 'shift', direction });
    }

    // Show a part from the parts list in the app, or show none.
    function glance(index: number | null): void {
        post({ type: 'glance', index });
    }

    // Point at a part the selected one sits in, so many steps out.
    function glanceUp(steps: number | null): void {
        post({ type: 'glance', up: steps });
    }

    // Select a part from the parts list.
    function pickPart(index: number): void {
        post({ type: 'pick', index });
    }

    // Select a part the selected one sits in, so many steps out.
    function pickUp(steps: number): void {
        post({ type: 'pick', up: steps });
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

        if (editing) {
            post({ type: 'outline' });
        }

        if (!editing) {
            deselect();
        }
    });

    watch(onlyThisOne, (value) => {
        save();
        known.value = null;
        post({ type: 'reach', instance: value });
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

    watch(moveFreely, (value) => {
        post({ type: 'free', enabled: value });

        try {
            window.localStorage.setItem(FREE_KEY, value ? '1' : '0');
        } catch {
            // Not kept: the choice lasts until the page closes.
        }
    });

    watch(zoom, (value) => post({ type: 'zoom', zoom: value }));

    // Handles only show on a part that can be changed in place.
    watch(editable, (enabled) => post({ type: 'handles', enabled }));

    // Show changes in the app straight away, before they are saved, on
    // every part changed since the app was last rebuilt.
    function showUnshown(
        to: Window | null | undefined = frame.value?.contentWindow,
    ): void {
        const parts = new Map<
            string,
            {
                location: { kind: string; value: string };
                values: Values;
                classes?: string;
            }
        >();

        for (const batch of [...saved.value, sending.value, ...queue.value]) {
            if (batch == null || batch.device !== device.value) {
                continue;
            }

            const kind =
                batch.target.instance === null
                    ? 'any'
                    : batch.target.instance
                      ? 'instance'
                      : 'source';
            const part = parts.get(batch.target.value) ?? {
                location: { kind, value: batch.target.value },
                values: {},
            };

            // An undo or redo sets the part's classes; later changes build
            // on them.
            if (batch.shows !== undefined) {
                part.values = {};
                part.classes = batch.shows;
            }

            Object.assign(part.values, batch.values);
            parts.set(batch.target.value, part);
        }

        post(
            {
                type: 'style',
                parts: [...parts.values()].map((part) => ({
                    location: part.location,
                    classes: part.classes,
                    styles: inlineStyles(part.values),
                })),
            },
            to,
        );
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
                frames.value = [];

                return;
            }

            if (frames.value.length === 0) {
                alike = null;
                frames.value = [
                    {
                        key: ++frameKeys,
                        src: showPreview(preview.id, {
                            query: { to: savedPath() },
                        }).url,
                        shows: 0,
                        revision: preview.revision,
                    },
                ];
            } else if (previous?.[1] !== preview.revision) {
                reload();
            }
        },
        { immediate: true },
    );

    // A rebuild takes a few seconds; asking each second shows it sooner.
    const { start, stop } = usePoll(
        1000,
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
    function move(
        to: SelectedElement,
        placement: 'before' | 'after',
        by: number | null = null,
    ): void {
        const part = selected.value;
        const seen = source.preview()?.revision ?? null;
        // Undo can move it back at once only when the app on show is the
        // saved one, so it counts the places as they are written.
        const fair =
            frames.value[0]?.revision === seen &&
            (head.value === null || head.value === seen);

        if (part !== null) {
            saveMove(part, to, placement, seen, fair ? by : null);
        }
    }

    // Save a move once the changes before it are saved: the app has moved
    // the part already, so a quick second move is saved too, not lost.
    function saveMove(
        part: SelectedElement,
        to: SelectedElement,
        placement: 'before' | 'after',
        seen: string | null,
        by: number | null = null,
    ): void {
        const preview = source.preview();
        // A part moves where it is placed on the page: the one use of a
        // shared piece, as the preview found its neighbours.
        const from = part.instance ?? part.source;

        if (preview === null || !from) {
            return;
        }

        // The part was found in an app that has since been replaced, so
        // its place may be wrong: show the app as it is saved instead.
        if (preview.revision !== seen) {
            reload();

            return;
        }

        if (moving.value || sending.value !== null || queue.value.length > 0) {
            save();
            setTimeout(() => saveMove(part, to, placement, seen, by), 200);

            return;
        }

        moving.value = true;
        movedFrom = preview.revision;
        saveError.value = null;
        const newest = source.edits()[0]?.id ?? 0;

        router.post(
            VisualMoveController.store.url(source.projectId()),
            {
                preview: preview.id,
                target: from,
                instance: Boolean(part.instance),
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
                        movedTo.value = moved.instance
                            ? { ...selected.value, instance: moved.target }
                            : { ...selected.value, source: moved.target };
                    }
                },
                onSuccess: () => {
                    const edit = source.edits()[0];

                    // The next change builds on this one, even before the
                    // app is rebuilt.
                    last.value = null;
                    head.value =
                        edit !== undefined && edit.id > newest
                            ? edit.revision
                            : null;
                    known.value = null;

                    if (edit !== undefined && edit.id > newest && by) {
                        shifted.set(edit.id, by);
                    }

                    inspect();
                },
                onError: (errors) => {
                    saveError.value = Object.values(errors)[0] ?? null;
                    // The app moved the part already: put it back.
                    reload();
                },
                onFinish: () => (moving.value = false),
            },
        );
    }

    // Put a copy of the selected part right after it, a new part after it,
    // or take it out of the page. The app shows it at once; changes waiting
    // to be saved go first, so this builds on them. A copy or a new part is
    // picked once it is saved.
    function reshape(how: 'duplicate' | 'remove'): void;
    function reshape(how: 'add', kind: NewPartKind): void;
    function reshape(how: Reshape, kind?: NewPartKind): void {
        const preview = source.preview();
        const part = selected.value;
        // A shared piece is copied or taken out where it is used.
        const at = part?.instance ?? part?.source;

        if (preview === null || part === null || !at) {
            return;
        }

        // Show it at once, even when an earlier change is still saving:
        // a second Ctrl+D is a second copy, not a lost key press.
        saveError.value = null;

        if (how === 'add') {
            fresh = null;
        }

        post({
            type: 'reshape',
            how,
            markup: kind === undefined ? undefined : newParts[kind].markup,
        });

        // What the app takes out can be put back on undo, when the app on
        // show is the saved one, so its places are the ones written.
        const removal = { spot: null as Spot | null };

        if (how === 'remove') {
            taking.push(removal);
            deselect();
        }

        const fair =
            frames.value[0]?.revision === preview.revision &&
            (head.value === null || head.value === preview.revision);

        saveReshape(
            how,
            part,
            at,
            preview.revision,
            fair ? removal : null,
            kind,
        );
    }

    // Save a copy or a removal once the changes before it are saved.
    function saveReshape(
        how: Reshape,
        part: SelectedElement,
        at: string,
        seen: string | null,
        removal: { spot: Spot | null } | null = null,
        kind?: NewPartKind,
    ): void {
        const preview = source.preview();

        if (preview === null) {
            return;
        }

        // The part was found in an app that has since been replaced, so
        // its place may be wrong: show the app as it is saved instead.
        if (preview.revision !== seen) {
            reload();

            return;
        }

        if (moving.value || sending.value !== null || queue.value.length > 0) {
            save();
            setTimeout(
                () => saveReshape(how, part, at, seen, removal, kind),
                200,
            );

            return;
        }

        moving.value = true;
        movedFrom = preview.revision;
        const newest = source.edits()[0]?.id ?? 0;

        const data = {
            preview: preview.id,
            target: at,
            instance: Boolean(part.instance),
            revision: head.value ?? element.value?.revision,
        };
        const options = {
            only: ['edits', 'preview'],
            async: true,
            preserveScroll: true,
            preserveState: true,
            onFlash: (flash: Record<string, unknown>) => {
                const copy = flash.moved as
                    | { target: string; instance: boolean }
                    | undefined;

                if (copy !== undefined && how === 'add') {
                    fresh = {
                        text: fresh?.text ?? '',
                        before: fresh?.before ?? '',
                        at: copy.target,
                    };
                }

                if (copy !== undefined && how !== 'remove') {
                    onlyThisOne.value = true;
                    movedTo.value = copy.instance
                        ? { ...part, instance: copy.target }
                        : { ...part, source: copy.target };
                }
            },
            onSuccess: () => {
                const edit = source.edits()[0];

                // The next change builds on this one, even before the app
                // is rebuilt.
                last.value = null;
                head.value =
                    edit !== undefined && edit.id > newest
                        ? edit.revision
                        : null;
                known.value = null;

                if (
                    edit !== undefined &&
                    edit.id > newest &&
                    removal?.spot != null
                ) {
                    taken.set(edit.id, removal.spot);
                }

                if (how !== 'remove') {
                    inspect();
                }
            },
            onError: (errors: Record<string, string>) => {
                saveError.value = Object.values(errors)[0] ?? null;
                fresh = null;
                // The app changed already: show it as it is saved.
                reload();
            },
            onFinish: () => (moving.value = false),
        };

        if (how === 'add') {
            router.post(
                NewPartController.store.url(source.projectId()),
                { ...data, part: kind },
                options,
            );
        } else if (how === 'duplicate') {
            router.post(
                VisualPartController.store.url(source.projectId()),
                data,
                options,
            );
        } else {
            router.delete(
                VisualPartController.destroy.url(source.projectId()),
                {
                    ...options,
                    data,
                },
            );
        }
    }

    // Put new words in the selected part, typed over it in the app or in
    // the panel. The app shows them at once; changes waiting to be saved go
    // first, so the words build on them.
    function reword(text: string, before?: string): void {
        const preview = source.preview();
        const part = selected.value;
        const words = text.replace(/\s+/g, ' ').trim();
        const was = before ?? part?.words ?? '';
        // Words are written where the part is placed: a shared piece's
        // words are filled in where it is used.
        const at = part?.instance ?? part?.source;

        if (
            preview === null ||
            part === null ||
            !at ||
            words === '' ||
            words === was
        ) {
            return;
        }

        if (sending.value !== null || queue.value.length > 0 || moving.value) {
            save();
            setTimeout(() => reword(text, before), 200);

            return;
        }

        post({ type: 'words', text: words });
        selected.value = { ...part, words, text: words.slice(0, 80) };
        moving.value = true;
        saveError.value = null;

        router.post(
            VisualTextController.store.url(source.projectId()),
            {
                preview: preview.id,
                target: at,
                instance: Boolean(part.instance),
                before: was,
                text: words,
                revision: head.value ?? element.value?.revision,
                places: part.places ?? [],
            },
            {
                only: ['edits', 'preview'],
                async: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    last.value = null;
                    head.value = null;
                    known.value = null;
                    inspect();
                },
                onError: (errors) => {
                    saveError.value = Object.values(errors)[0] ?? null;
                    selected.value = part;
                    // The app shows the new words already: put the old back.
                    reload();
                },
                onFinish: () => (moving.value = false),
            },
        );
    }

    // Send a link to a new address. Nothing shows in the app, so the new
    // address is written, and the app rebuilds with it.
    function relink(href: string): void {
        const preview = source.preview();
        const part = selected.value;
        const address = href.trim();
        const was = element.value?.link?.href ?? null;
        const at = part?.instance ?? part?.source;

        if (
            preview === null ||
            part === null ||
            !at ||
            was === null ||
            address === '' ||
            address === was
        ) {
            return;
        }

        if (sending.value !== null || queue.value.length > 0 || moving.value) {
            save();
            setTimeout(() => relink(href), 200);

            return;
        }

        moving.value = true;
        saveError.value = null;

        router.post(
            VisualLinkController.store.url(source.projectId()),
            {
                preview: preview.id,
                target: at,
                instance: Boolean(part.instance),
                before: was,
                href: address,
                revision: head.value ?? element.value?.revision,
            },
            {
                only: ['edits', 'preview'],
                async: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    last.value = null;
                    head.value = null;
                    known.value = null;
                    inspect();
                },
                onError: (errors) => {
                    saveError.value = Object.values(errors)[0] ?? null;
                },
                onFinish: () => (moving.value = false),
            },
        );
    }

    // Show a theme colour on every part drawn in it, before it is saved.
    function recolor(token: string, value: string): void {
        recolored.set(token, { value, revision: null });
        post({ type: 'recolor', token, value });
    }

    // Show the app's light or dark look. A colour shown on top belongs to
    // the look it was chosen in, so it goes; a saved one shows once built.
    function showLook(to: 'light' | 'dark'): void {
        forgetColors();
        look.value = to;
        post({ type: 'look', dark: to === 'dark' });
    }

    // Show the app's own theme colours again.
    function forgetColors(): void {
        for (const token of recolored.keys()) {
            post({ type: 'recolor', token, value: null });
        }

        recolored.clear();
    }

    // Save a theme colour for the look the app shows now. Changes waiting
    // to be saved go first, so the colour builds on them.
    function saveColor(token: string, value: string): void {
        const preview = source.preview();

        if (preview === null) {
            return;
        }

        recolor(token, value);

        if (sending.value !== null || queue.value.length > 0 || moving.value) {
            save();
            setTimeout(() => saveColor(token, value), 200);

            return;
        }

        const newest = source.edits()[0]?.id ?? 0;
        moving.value = true;
        saveError.value = null;

        router.post(
            ThemeColorController.store.url(source.projectId()),
            {
                preview: preview.id,
                mode: themeDark.value ? 'dark' : 'light',
                token,
                color: value,
                revision:
                    head.value ?? element.value?.revision ?? preview.revision,
            },
            {
                only: ['edits', 'preview'],
                async: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    const edit = source.edits()[0];

                    if (
                        edit !== undefined &&
                        edit.id > newest &&
                        recolored.get(token)?.value === value
                    ) {
                        recolored.set(token, {
                            value,
                            revision: edit.revision,
                        });
                    }

                    last.value = null;
                    head.value = null;
                    known.value = null;
                    inspect();
                },
                onError: (errors) => {
                    saveError.value = Object.values(errors)[0] ?? null;
                    forgetColors();
                },
                onFinish: () => (moving.value = false),
            },
        );
    }

    // Once the app on show is built with a saved colour, it no longer needs
    // showing on top.
    watch(
        () => [source.preview()?.revision, source.edits()] as const,
        ([revision, edits]) => {
            for (const [token, { revision: at, step }] of recolored) {
                const stepped = edits.find((edit) => edit.id === step?.edit);
                const built =
                    step === undefined
                        ? at
                        : stepped?.revision !== step.from
                          ? stepped?.revision
                          : null;

                if (built != null && built === revision) {
                    recolored.delete(token);
                }
            }
        },
    );

    watch(element, (now) => {
        if (
            dropped === null ||
            !now ||
            now.target !== target.value?.value ||
            now.reason === 'updating'
        ) {
            return;
        }

        const picture = dropped;
        dropped = null;

        if (now.picture?.src != null) {
            repicture(picture);
        } else {
            saveError.value =
                'Your app decides which picture shows here. Ask me to change it.';
            // The app shows the dropped file already: take it back.
            reload();
        }
    });

    // Put a new picture in the picked one. The app shows it at once from
    // the owner's own file; the file is kept in the app, which rebuilds
    // with it.
    function repicture(picture: File): void {
        const preview = source.preview();
        const part = selected.value;
        const was = element.value?.picture?.src ?? null;
        const at = part?.instance ?? part?.source;

        if (preview === null || part === null || !at || was === null) {
            return;
        }

        if (sending.value !== null || queue.value.length > 0 || moving.value) {
            save();
            setTimeout(() => repicture(picture), 200);

            return;
        }

        moving.value = true;
        saveError.value = null;
        post({ type: 'picture', picture });

        const newest = source.edits()[0]?.id ?? 0;
        const place = location();
        chosen =
            place === null
                ? null
                : { location: place, picture, revision: null };

        router.post(
            VisualPictureController.store.url(source.projectId()),
            {
                preview: preview.id,
                target: at,
                instance: Boolean(part.instance),
                before: was,
                picture,
                revision: head.value ?? element.value?.revision,
            },
            {
                only: ['edits', 'preview'],
                async: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    last.value = null;
                    head.value = null;
                    known.value = null;

                    const edit = source.edits()[0];

                    if (chosen?.picture === picture) {
                        chosen =
                            edit !== undefined && edit.id > newest
                                ? { ...chosen, revision: edit.revision }
                                : null;
                    }

                    inspect();
                },
                onError: (errors) => {
                    saveError.value = Object.values(errors)[0] ?? null;
                    chosen = null;
                    // The app shows the owner's file already: take it back.
                    reload();
                },
                onFinish: () => (moving.value = false),
            },
        );
    }

    // Go back to the page before this one, or forward again. The frame's
    // own history is not used: at its first page it would move the builder.
    function browse(by: -1 | 1): void {
        const preview = source.preview();
        const to = visited.value[visitedAt.value + by];

        if (preview === null || to === undefined) {
            return;
        }

        visitedAt.value += by;
        post({ type: 'go', href: preview.origin + to });
        deselect();
    }

    const back = () => browse(-1);
    const forward = () => browse(1);
    const canGoBack = computed(() => visitedAt.value > 0);
    const canGoForward = computed(
        () => visitedAt.value < visited.value.length - 1,
    );

    // Open a page of the app, as a link in an email the app sent does.
    function visit(href: string): void {
        const preview = source.preview();

        if (preview === null || !href.startsWith(preview.origin)) {
            return;
        }

        post({ type: 'go', href });
        deselect();
    }

    // An address as the owner reads it: a page of the app by its path,
    // any other site in full.
    function addressOf(href: string): string {
        const preview = source.preview();

        try {
            const to = new URL(href);

            return preview !== null &&
                to.origin === new URL(preview.origin).origin
                ? to.pathname + to.search + to.hash
                : to.href;
        } catch {
            return href;
        }
    }

    // Go where a link goes. A page of the app opens in the preview, still
    // designing; any other site opens in a new tab, as it would for a
    // visitor.
    function follow(href: string): void {
        const preview = source.preview();

        if (preview === null || href.trim() === '') {
            return;
        }

        let to: URL;

        try {
            to = new URL(href.trim(), preview.origin + framePath.value);
        } catch {
            return;
        }

        if (to.protocol !== 'http:' && to.protocol !== 'https:') {
            return;
        }

        if (to.origin === new URL(preview.origin).origin) {
            post({ type: 'go', href: to.href });
            deselect();
        } else {
            window.open(to.href, '_blank', 'noopener');
        }
    }

    // Whether an edit is undone, counting undo and redo the server has not
    // done yet.
    function isUndone(edit: VisualEditSummary): boolean {
        return claims.value.get(edit.id)?.undone ?? edit.reverted_at !== null;
    }

    // Undo takes back the newest change still in place. Redo puts back the
    // change undone last, like any editor: a change made after an undo
    // ends what can be redone from before it.
    const undoable = computed(() =>
        source.edits().find((edit) => !isUndone(edit)),
    );
    const redoable = computed(() => {
        let newest = 0;
        let edit: VisualEditSummary | undefined;

        for (const candidate of source.edits()) {
            // Undone in the app, not yet on the server: undone just now.
            const undoneAt = claims.value.get(candidate.id)?.undone
                ? Infinity
                : Date.parse(candidate.reverted_at ?? '');

            if (!isUndone(candidate) || !(undoneAt >= newest)) {
                break;
            }

            edit = candidate;
            newest = Math.max(newest, Date.parse(candidate.created_at ?? ''));
        }

        return edit;
    });

    // Versions of the app that hold the same thing: an undone change's
    // version and the version before that change, or a redone change's
    // new version and the one it first made.
    const same = new Map<string, string>();

    function find(revision: string): string {
        let at = revision;

        while (same.has(at) && same.get(at) !== at) {
            at = same.get(at) as string;
        }

        return at;
    }

    function join(one: string | null, other: string | null): void {
        if (one !== null && other !== null && find(one) !== find(other)) {
            same.set(find(one), find(other));
        }
    }

    // Whether the page on show holds a version of the app.
    function holds(revision: string | null): boolean {
        if (revision === null) {
            return false;
        }

        for (const edit of source.edits()) {
            if (edit.reverted_at !== null) {
                join(edit.revision, edit.base);
            }
        }

        const shown = alike ?? new Set([frames.value[0]?.revision ?? null]);

        return [...shown].some(
            (held) => held !== null && find(held) === find(revision),
        );
    }

    // Take an edit back, or put it back, in the running app straight away.
    // The server follows, one step at a time.
    function claim(edit: VisualEditSummary, key: Step): void {
        const side = edit.sides?.[key === 'undo' ? 'before' : 'after'];
        const other = edit.sides?.[key === 'undo' ? 'after' : 'before'];
        const shown: Batch | null =
            side === undefined
                ? null
                : {
                      target: { value: edit.target, instance: null },
                      device: edit.device,
                      // A side names only what it sets, so what the other
                      // side set is cleared, or the panel keeps showing it.
                      values: {
                          ...Object.fromEntries(
                              Object.keys(other?.values ?? {}).map(
                                  (property) => [property, null],
                              ),
                          ),
                          ...side.values,
                      },
                      classes: side.classes,
                      revision: edit.revision,
                      shows: side.classes,
                  };

        claims.value.set(edit.id, { undone: key === 'undo', shown });

        // Whether the app on show holds the version this step starts from:
        // right after the change to undo it, or right before it to redo it.
        // Only then are parts where the change left them.
        const ready =
            key === 'undo'
                ? holds(edit.revision) || holds(edit.commit)
                : holds(edit.revision) || holds(edit.base);

        // Once this step shows, the app holds the version on its other side,
        // so the next step can show at once too. A part added, taken out or
        // moved shifts where the parts after it are written, so the places
        // the app knows its parts by hold only until it is rebuilt.
        const after = key === 'undo' ? edit.base : edit.commit;
        const shifts = ['duplicate', 'add', 'remove', 'move'].includes(
            edit.kind,
        );
        alike = new Set(ready && !shifts && after !== null ? [after] : []);

        // A copy undone, or a part removed again, goes at once while the app
        // is the version right after it, where the part is still at its place.
        if (
            ((key === 'undo' &&
                (edit.kind === 'duplicate' || edit.kind === 'add')) ||
                (key === 'redo' && edit.kind === 'remove')) &&
            ready &&
            (edit.kind !== 'remove' || edit.removed !== null)
        ) {
            // A removal names the part left picked; the part itself is
            // where it was written.
            post({
                type: 'take',
                location: {
                    kind: 'any',
                    value: edit.kind === 'remove' ? edit.removed : edit.target,
                },
                edit: edit.id,
            });

            if (selected.value?.source === edit.target) {
                deselect();
            } else {
                post({ type: 'outline' });
            }
        }

        // A part moved goes back at once, by as many places.
        const by = shifted.get(edit.id);

        if (
            key === 'undo' &&
            edit.kind === 'move' &&
            by !== undefined &&
            ready
        ) {
            post({
                type: 'budge',
                location: { kind: 'any', value: edit.target },
                by: -by,
            });
            post({ type: 'outline' });
        }

        // A new picture, or the one it replaced, shows at once, while the
        // app is the version the picture is at its place in.
        const picture = key === 'undo' ? edit.picture_before : edit.picture;

        // A theme colour undone or redone shows at once, in the look it
        // was changed for; in the other look it shows once the app is
        // rebuilt, and the colour chosen on top must not hide it.
        if (edit.kind === 'theme') {
            forgetColors();

            const theme = edit.theme;

            if (theme !== null && (theme.mode === 'dark') === themeDark.value) {
                const value = key === 'undo' ? theme.before : theme.after;

                recolored.set(theme.token, {
                    value,
                    revision: null,
                    step: { edit: edit.id, from: edit.revision },
                });
                post({ type: 'recolor', token: theme.token, value });
            }
        }

        if (edit.kind === 'picture') {
            chosen = null;
        }

        if (edit.kind === 'picture' && picture !== null && ready) {
            post({
                type: 'picture',
                location: { kind: 'any', value: edit.target },
                src: picture,
            });
        }

        // A part taken out comes back at once the same way.
        const spot = taken.get(edit.id);

        if (
            ((key === 'undo' && edit.kind === 'remove') ||
                (key === 'redo' &&
                    (edit.kind === 'duplicate' || edit.kind === 'add'))) &&
            spot != null &&
            ready
        ) {
            post({ type: 'put', spot });
            post({ type: 'outline' });
        }

        // New words show at once too, without waiting for the rebuild.
        const words = key === 'undo' ? edit.words_before : edit.words;

        if (edit.kind === 'text' && words !== null) {
            post({
                type: 'words',
                location: { kind: 'any', value: edit.target },
                text: words,
            });

            // Words written in another file are not where the part is, so
            // the part the owner picked is put right by what it shows.
            const part = selected.value;
            const shownNow = key === 'undo' ? edit.words : edit.words_before;

            if (part !== null && part.words === shownNow) {
                post({ type: 'words', text: words });
                selected.value = { ...part, words, text: words.slice(0, 80) };
            }
        }

        if (shown !== null) {
            saved.value.push(shown);
            showUnshown();
        }
    }

    // Undo or redo one saved change from the list of recent changes.
    function step(edit: VisualEditSummary): void {
        const key = isUndone(edit) ? 'redo' : 'undo';

        saveError.value = null;
        claim(edit, key);
        steps.value.push({ key, edit });
        nextStep();
    }

    // Undo or redo, as in any editor, however fast the owner presses. A
    // change not saved yet is simply taken back. A saved one shows undone
    // at once and is undone on the server in order. A press made while a
    // change is being saved waits for that change.
    function press(key: Step): void {
        saveError.value = null;

        if (
            key === 'undo' &&
            queue.value.length > 0 &&
            steps.value.length === 0
        ) {
            undone.value.push(queue.value.pop() as Batch);

            if (queue.value.length === 0) {
                clearTimeout(timer);
            }

            showUnshown();

            return;
        }

        if (
            key === 'redo' &&
            undone.value.length > 0 &&
            steps.value.length === 0
        ) {
            queue.value.push(undone.value.pop() as Batch);
            showUnshown();
            schedule();

            return;
        }

        if (sending.value !== null || moving.value) {
            steps.value.push({ key, edit: null });

            return;
        }

        const edit = key === 'redo' ? redoable.value : undoable.value;

        if (edit !== undefined) {
            claim(edit, key);
            steps.value.push({ key, edit });
            nextStep();
        }
    }

    // Send the oldest waiting undo or redo, once no change is being saved.
    function nextStep(): void {
        const next = steps.value[0];

        if (
            next === undefined ||
            sending.value !== null ||
            queue.value.length > 0 ||
            moving.value ||
            stepping.value
        ) {
            return;
        }

        steps.value.shift();

        const edit =
            next.edit ??
            (next.key === 'redo' ? redoable.value : undoable.value);

        if (edit === undefined) {
            steps.value = [];

            return;
        }

        if (next.edit === null) {
            claim(edit, next.key);
        }

        stepping.value = true;

        // Undo takes a copy away: the copy can no longer stay picked.
        if (
            next.key === 'undo' &&
            (edit.kind === 'duplicate' || edit.kind === 'add') &&
            selected.value?.source === edit.target
        ) {
            deselect();
        }

        const options = {
            only: ['edits', 'preview'],
            async: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                claims.value.delete(edit.id);

                // A redone change is made again as a new version.
                const now = source.edits().find((one) => one.id === edit.id);

                if (next.key === 'redo' && now !== undefined) {
                    join(now.commit, edit.commit);
                }

                last.value = null;
                head.value = null;
                known.value = null;
                inspect();
            },
            onError: (errors: Record<string, string>) => {
                // Stop, and show the app as the server has it.
                saveError.value = Object.values(errors)[0] ?? null;
                steps.value = [];

                const shown = [...claims.value.values()].map(
                    (claimed) => claimed.shown,
                );

                saved.value = saved.value.filter(
                    (batch) => !shown.includes(batch),
                );
                claims.value.clear();
                forgetColors();
                showUnshown();
            },
            onFinish: () => {
                stepping.value = false;
                nextStep();
            },
        };

        if (next.key === 'undo') {
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

    // Presses wait while changes save.
    watch(saving, (busy) => busy || nextStep());

    const canUndo = computed(
        () => queue.value.length > 0 || undoable.value !== undefined,
    );
    const canRedo = computed(
        () => undone.value.length > 0 || redoable.value !== undefined,
    );

    // Hide the selected part on this screen size. Undo shows it again.
    function hide(): void {
        if (editable.value) {
            change('layout', 'hidden');
        }
    }

    // Keys pressed in the builder, outside the app. Typing in a field keeps
    // the field's own keys.
    function onKey(event: KeyboardEvent): void {
        const into = event.target as HTMLElement | null;
        const typing = into?.closest(
            'input:not([type=range]):not([type=checkbox]):not([type=radio]):not([type=button]), textarea, select, [contenteditable]',
        );
        const inside = into?.closest(
            '[role=dialog], [role=menu], [role=listbox]',
        );
        const key = event.key.toLowerCase();
        const mod = event.metaKey || event.ctrlKey;

        if (!source.designing.value || typing || event.defaultPrevented) {
            return;
        }

        if (mod && (key === 'z' || key === 'y')) {
            event.preventDefault();
            press(key === 'y' || event.shiftKey ? 'redo' : 'undo');
        } else if (
            event.altKey &&
            key.startsWith('arrow') &&
            selected.value !== null &&
            !inside
        ) {
            // As in the app: move the part the way the arrow points.
            event.preventDefault();
            post({ type: 'toward', key });
        } else if (mod && key === 'd' && selected.value !== null && !inside) {
            event.preventDefault();
            reshape('duplicate');
        } else if (
            mod &&
            event.altKey &&
            (event.code === 'KeyC' || event.code === 'KeyV') &&
            selected.value !== null &&
            !inside
        ) {
            event.preventDefault();

            if (event.code === 'KeyC') {
                copyLook();
            } else {
                pasteLook();
            }
        } else if (key === 'escape' && selected.value !== null && !inside) {
            deselect();
        } else if (
            (key === 'delete' || key === 'backspace') &&
            selected.value !== null &&
            !inside
        ) {
            event.preventDefault();
            hide();
        }
    }

    onMounted(() => window.addEventListener('keydown', onKey));
    onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

    return reactive({
        frame,
        frames,
        drawn,
        bind,
        frameWidth,
        // The address of the app page on show, as in "/login".
        path: framePath,
        device,
        running,
        lost,
        selected,
        onlyThisOne,
        element,
        saving,
        updating,
        upToDate,
        saveError,
        target,
        change,
        nudge,
        hold,
        fine,
        moveFreely,
        theme,
        zoom,
        dragging,
        pickNear,
        parts,
        glance,
        glanceUp,
        pickPart,
        pickUp,
        neighbours,
        shift,
        deselect,
        reload,
        current,
        valueOf,
        save,
        step,
        isUndone,
        showSpacing,
        reword,
        relink,
        repicture,
        follow,
        addressOf,
        back,
        canGoBack,
        forward,
        canGoForward,
        visit,
        press,
        hide,
        reshape,
        copyLook,
        pasteLook,
        copiedLook,
        colors,
        saveColor,
        recolor,
        forgetColors,
        themeDark,
        themeLooks,
        showLook,
        undoable,
        redoable,
        canUndo,
        canRedo,
    });
}

export type AppPreviewState = ReturnType<typeof useAppPreview>;
