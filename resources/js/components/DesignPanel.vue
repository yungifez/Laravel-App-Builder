<script setup lang="ts">
import { Form, Link, usePage } from '@inertiajs/vue3';
import {
    AlignCenterVertical,
    AlignEndVertical,
    AlignHorizontalDistributeCenter,
    AlignHorizontalJustifyCenter,
    AlignHorizontalJustifyEnd,
    AlignHorizontalJustifyStart,
    AlignHorizontalSpaceAround,
    AlignHorizontalSpaceBetween,
    AlignStartVertical,
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Check,
    ChevronDown,
    ChevronUp,
    Crosshair,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowRightToLine,
    ArrowUp,
    ArrowUpRight,
    Baseline,
    Columns3,
    Copy,
    EyeOff,
    LayoutGrid,
    LoaderCircle,
    MessageSquare,
    MousePointerClick,
    Redo2,
    Rows3,
    Sparkles,
    StretchVertical,
    TextWrap,
    Trash2,
    Undo2,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import type { Component } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import PageConsistencyController from '@/actions/App/Http/Controllers/PageConsistencyController';
import MeasureField from '@/components/design/MeasureField.vue';
import Reveal from '@/components/design/Reveal.vue';
import Segmented from '@/components/design/Segmented.vue';
import SpacingBox from '@/components/design/SpacingBox.vue';
import StepSlider from '@/components/design/StepSlider.vue';
import Swatches from '@/components/design/Swatches.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { AppPreviewState, Way } from '@/composables/useAppPreview';
import { when } from '@/lib/when';
import {
    definition,
    describeValue,
    drawnStep,
    properties,
    weights,
} from '@/lib/visualProperties';
import type {
    EditorPreview,
    InspectedElement,
    VisualEditSummary,
    VisualProperty,
    VisualValue,
} from '@/types';

const props = defineProps<{
    projectId: number;
    preview: EditorPreview | null;
    edits: VisualEditSummary[];
    state: AppPreviewState;
}>();

const asking = ref(false);

// The words of the selected part, as the owner types them. They are saved
// when the owner leaves the box or presses Enter.
const draft = ref('');
watch(
    () => props.state.selected?.words,
    (words) => (draft.value = words ?? ''),
    { immediate: true },
);

function keepWords(): void {
    props.state.reword(draft.value);
}

// The three parts nearest around the selected one, outermost first.
const trail = computed(() =>
    (props.state.selected?.trail ?? [])
        .slice(0, 3)
        .map((step, index) => ({ ...step, up: index + 1 }))
        .reverse(),
);
const trailCut = computed(() => (props.state.selected?.trail?.length ?? 0) > 3);

// Where the selected link goes, as the owner types it. Saved like words.
const address = ref('');
watch(
    () => props.state.element?.link?.href,
    (href) => (address.value = href ?? ''),
    { immediate: true },
);

function keepAddress(): void {
    props.state.relink(address.value);
}

// How many parts up the link this part sits in is, to pick it at once.
const linkUp = computed(() => {
    const at = (props.state.selected?.trail ?? []).findIndex(
        (step) => step.kind === 'Link',
    );

    return at === -1 ? null : at + 1;
});

// Where "Go there" goes: the address being typed for a link the owner can
// change, or else where the app sends the link now.
const destination = computed(() =>
    props.state.element?.link?.href != null
        ? address.value.trim()
        : (props.state.selected?.href ?? ''),
);
// The list of parts and a part's details share one scroll. A part opens
// at its top, and closing it goes back to the same place in the list.
const scroller = ref<HTMLElement | null>(null);
let listScroll = 0;

watch(
    () => props.state.selected === null,
    (none, wasNone) => {
        const box = scroller.value;

        if (box === null || none === wasNone) {
            return;
        }

        if (none) {
            void nextTick(() => (box.scrollTop = listScroll));
        } else {
            listScroll = box.scrollTop;
            box.scrollTop = 0;
        }
    },
    { flush: 'pre' },
);

// Whether the owner is reading what making the page consistent does.
const tidying = ref(false);
const element = computed(() => props.state.element);

// "today" and "yesterday" read on their own; a date needs "on".
const originWhen = computed(() => {
    const said = when(element.value?.origin?.at ?? null);

    return said === '' || said === 'today' || said === 'yesterday'
        ? said
        : `on ${said}`;
});

// What else a change here may reach, said before the owner asks, with
// whether tests check it: every change runs them all (direction 18 §3).
const reach = computed(() => {
    const affects = element.value?.area?.affects ?? [];

    if (affects.length === 0) {
        return '';
    }

    const untested = affects.filter((area) => !area.tested);

    return [
        `This may also affect ${listed(affects.map((area) => area.name))}.`,
        untested.length === 0
            ? 'I check those too.'
            : `Nothing checks ${listed(untested.map((area) => area.name))} yet.`,
    ].join(' ');
});

function listed(names: string[]): string {
    return names.length < 2
        ? (names[0] ?? '')
        : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

// Where the part lives in the code, and its Tailwind classes, only for
// someone who chose to see how changes are built (§28.4).
const page = usePage();
const showCode = computed(() => (page.props.auth.user.detail_level ?? 1) >= 3);

// The choices a property offers, with an icon where one says it better.
const icons: Partial<Record<string, Component>> = {
    'layout:block': Rows3,
    'layout:flex': Columns3,
    'layout:grid': LayoutGrid,
    'layout:hidden': EyeOff,
    'direction:across': ArrowRight,
    'direction:down': ArrowDown,
    'wrap:wrap': TextWrap,
    'wrap:nowrap': ArrowRightToLine,
    'align:start': AlignStartVertical,
    'align:center': AlignCenterVertical,
    'align:end': AlignEndVertical,
    'align:stretch': StretchVertical,
    'align:baseline': Baseline,
    'justify:start': AlignHorizontalJustifyStart,
    'justify:center': AlignHorizontalJustifyCenter,
    'justify:end': AlignHorizontalJustifyEnd,
    'justify:between': AlignHorizontalSpaceBetween,
    'justify:around': AlignHorizontalSpaceAround,
    'justify:evenly': AlignHorizontalDistributeCenter,
    'text_align:left': AlignLeft,
    'text_align:center': AlignCenter,
    'text_align:right': AlignRight,
    'text_align:justify': AlignJustify,
};

function options(
    property: VisualProperty,
    only?: VisualValue[],
): { value: VisualValue; label: string; short?: string; icon?: Component }[] {
    const { input } = definition(property);

    if (input.kind !== 'choice') {
        return [];
    }

    return input.options
        .filter((option) => only === undefined || only.includes(option.value))
        .map((option) => ({
            ...option,
            icon: icons[`${property}:${option.value}`],
        }));
}

// The words a number property can be instead, for a row of narrow
// buttons: only the ones short enough to fit.
function words(
    property: VisualProperty,
): { value: VisualValue; label: string }[] {
    const { input } = definition(property);

    return input.kind === 'measure'
        ? (input.keywords ?? [])
              .filter((word) => word.short !== undefined)
              .map((word) => ({ value: word.value, label: word.short! }))
        : [];
}

const layout = computed(() =>
    String(props.state.valueOf('layout') ?? '').replace('inline-', ''),
);
const inline = computed(() =>
    String(props.state.valueOf('layout') ?? '').startsWith('inline'),
);

const sizes = options('text_size');

// The widest a part gets, narrow to wide, so dragging the slider widens
// it step by step. The named widths sit where they fall among the sizes.
const widthNames: Record<string, string> = {
    prose: 'Reading',
    full: 'Its space',
    none: 'No limit',
};
const widest = [
    'xs',
    'sm',
    'md',
    'lg',
    'xl',
    '2xl',
    'prose',
    '3xl',
    '4xl',
    '5xl',
    '6xl',
    '7xl',
    'full',
    'none',
].map((value) => ({
    value,
    label:
        widthNames[value] ??
        options('max_width').find((option) => option.value === value)?.label ??
        value,
}));

// Moving a part among the ones next to it, named the way the owner sees
// it: up and down in a column, left and right in a row. A side with no
// neighbour still shows, turned off, so the pair keeps its place.
const opposite: Record<Way, Way> = {
    up: 'down',
    down: 'up',
    left: 'right',
    right: 'left',
};
const wayOrder: Way[] = ['up', 'left', 'down', 'right'];
const wayLooks: Record<Way, { name: string; icon: Component; key: string }> = {
    up: { name: 'Up', icon: ArrowUp, key: '↑' },
    down: { name: 'Down', icon: ArrowDown, key: '↓' },
    left: { name: 'Left', icon: ArrowLeft, key: '←' },
    right: { name: 'Right', icon: ArrowRight, key: '→' },
};
const moves = computed(() => {
    const { earlier, later } = props.state.neighbours;

    if (earlier === null && later === null) {
        return [];
    }

    return [
        {
            step: -1 as const,
            way: earlier ?? opposite[later!],
            can: earlier !== null,
        },
        {
            step: 1 as const,
            way: later ?? opposite[earlier!],
            can: later !== null,
        },
    ].sort((a, b) => wayOrder.indexOf(a.way) - wayOrder.indexOf(b.way));
});

function set(property: VisualProperty, value: VisualValue | null): void {
    props.state.change(property, value);
}

const reasons: Record<NonNullable<InspectedElement['reason']>, string> = {
    updating: 'Your last change is still going in. Try again in a moment.',
    behind: 'Your app is showing an older version. Start it again to change this part.',
    not_found: "I can't find this part. Ask me to change it instead.",
    dynamic: 'This part changes while the app runs. Ask me instead.',
};

// What a change to the selected part reaches, and why it may not always
// look as it does now, one short line each.
const notes = computed(() => {
    const part = props.state.selected;

    if (part === null) {
        return [];
    }

    const copies =
        (props.state.onlyThisOne && part.instance
            ? part.copies?.instance
            : part.copies?.source) ?? 1;
    const lines: string[] = [];

    if (copies > 1) {
        lines.push(
            `One of ${copies} ${part.loop ? 'in a list' : 'like it'} on this page. A change here changes all ${copies}.`,
        );
    } else if (part.loop) {
        lines.push('One item of a list. A change here changes every item.');
    }

    if (part.when === 'either') {
        lines.push('Shows only at times. Something else shows here otherwise.');
    } else if (part.when === 'if') {
        lines.push('Shows only at times.');
    } else if (part.when === 'show') {
        lines.push('Hidden at times, for example until someone opens it.');
    }

    if (part.drawnBy) {
        const what =
            {
                canvas: 'chart or drawing',
                svg: 'drawing',
                iframe: 'page',
                video: 'video',
            }[part.drawnBy] ?? 'content';

        lines.push(
            `This ${what} is made by your app as it runs. You can change the part around it here. To change the ${what} itself, ask me.`,
        );
    }

    return lines;
});

// What a saved edit changed, in a few words.
function describeEdit(edit: VisualEditSummary): string {
    if (edit.kind === 'move') {
        return 'Moved';
    }

    if (edit.kind === 'text') {
        return 'Words';
    }

    if (edit.kind === 'link') {
        return 'Link';
    }

    if (edit.kind === 'duplicate') {
        return 'Copied';
    }

    if (edit.kind === 'remove') {
        return 'Removed';
    }

    return edit.properties
        .map(
            (key) =>
                properties.find((property) => property.key === key)?.label ??
                key,
        )
        .join(', ');
}

// What a change set its one property to, in words, with the colour itself
// when it is a colour.
function describeResult(
    edit: VisualEditSummary,
): { words: string; color: string | null } | null {
    const [property] = edit.properties;

    if (edit.kind === 'text' && edit.words) {
        return { words: `“${edit.words}”`, color: null };
    }

    if (edit.kind === 'link' && edit.link) {
        return { words: edit.link, color: null };
    }

    if (edit.kind !== 'look' || edit.properties.length !== 1) {
        return null;
    }

    const value = edit.sides?.after.values[property];

    if (value === null || value === undefined) {
        return null;
    }

    const words = describeValue(definition(property), value);

    const color =
        definition(property).group === 'Colours' && value !== 'transparent'
            ? (props.state.theme[String(value)] ?? `var(--${value})`)
            : null;

    return { words, color };
}

// The changes still in place, newest first. A part changed the same way
// again shows once, as its latest change; undone changes leave the list,
// and the redo button brings them back.
const recent = computed(() => {
    const seen = new Set<string>();

    return props.edits.filter((edit) => {
        if (props.state.isUndone(edit)) {
            return false;
        }

        const key = `${edit.target}|${edit.kind}|${edit.properties.join(',')}`;

        if (seen.has(key)) {
            return false;
        }

        seen.add(key);

        return true;
    });
});
</script>

<template>
    <div class="flex min-h-0 flex-col" data-test="inspector">
        <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto">
            <div
                v-if="!preview || preview.status !== 'ready' || state.lost"
                class="flex items-center justify-center gap-2 p-4 text-sm text-muted-foreground lg:flex-col lg:py-16"
            >
                <MousePointerClick class="size-5 lg:size-8" />
                Open your app first
            </div>

            <template v-else-if="state.selected === null">
                <div
                    class="flex items-center justify-center gap-2 p-4 text-sm text-muted-foreground lg:flex-col lg:py-10"
                    data-test="design-empty"
                >
                    <MousePointerClick class="size-5 lg:size-8" />
                    Click any part of your app
                </div>

                <!-- A change I decide on, so the owner reads what it may do
                     before asking. -->
                <section class="px-4 pb-4" data-test="make-consistent">
                    <button
                        v-if="!tidying"
                        type="button"
                        class="flex min-h-11 w-full items-center gap-2 rounded-md border border-dashed px-3 text-sm text-muted-foreground select-none hover:text-foreground sm:min-h-9"
                        data-test="make-consistent-open"
                        @click="tidying = true"
                    >
                        <Sparkles class="size-4" /> Make this page consistent
                    </button>
                    <Form
                        v-else
                        v-bind="PageConsistencyController.store.form(projectId)"
                        v-slot="{ errors, processing }"
                        class="space-y-2 rounded-md border p-3"
                        @success="tidying = false"
                    >
                        <input type="hidden" name="path" :value="state.path" />
                        <p class="text-sm">
                            I'll make the parts of this page match.
                        </p>
                        <p class="text-sm text-muted-foreground">
                            I decide what to change, so it can turn out
                            differently each time and change parts you did not
                            pick. You can undo it.
                        </p>
                        <InputError :message="errors.path" />
                        <div class="flex gap-2">
                            <Button
                                :disabled="processing"
                                class="h-11 flex-1 select-none sm:h-8"
                                data-test="make-consistent-confirm"
                            >
                                Go ahead
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                class="h-11 select-none sm:h-8"
                                @click="tidying = false"
                            >
                                Cancel
                            </Button>
                        </div>
                    </Form>
                </section>

                <section
                    v-if="recent.length > 0"
                    class="hidden px-4 pb-4 lg:block"
                    data-test="recent-edits"
                >
                    <h3 class="pb-1 text-xs font-medium text-muted-foreground">
                        Recent
                    </h3>
                    <ul class="text-sm">
                        <li
                            v-for="edit in recent"
                            :key="edit.id"
                            class="flex min-h-11 items-center gap-2 sm:min-h-9"
                        >
                            <span class="min-w-0 flex-1 truncate">{{
                                describeEdit(edit)
                            }}</span>
                            <span
                                v-if="describeResult(edit)"
                                class="flex min-w-0 shrink items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <span
                                    v-if="describeResult(edit)?.color"
                                    class="size-3 shrink-0 rounded-full border"
                                    :style="{
                                        background:
                                            describeResult(edit)?.color ??
                                            undefined,
                                    }"
                                />
                                <span class="truncate">{{
                                    describeResult(edit)?.words
                                }}</span>
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-11 shrink-0 text-muted-foreground sm:size-7"
                                :disabled="state.saving"
                                :aria-label="`Undo ${describeEdit(edit)}`"
                                title="Undo"
                                :data-test="`undo-edit-${edit.id}`"
                                @click="state.step(edit)"
                            >
                                <Undo2 class="size-3.5" />
                            </Button>
                        </li>
                    </ul>
                </section>

                <!-- Every part of the page on show, so a small or hidden
                     part is as easy to pick as a big one. -->
                <section
                    v-if="state.parts.length > 0"
                    class="px-4 pb-4"
                    data-test="page-parts"
                >
                    <h3 class="pb-1 text-xs font-medium text-muted-foreground">
                        Parts of this page
                    </h3>
                    <ul class="text-sm" @mouseleave="state.glance(null)">
                        <li v-for="(part, index) in state.parts" :key="index">
                            <button
                                type="button"
                                class="flex min-h-11 w-full min-w-0 items-center gap-2 rounded-md pr-2 text-left select-none hover:bg-muted focus-visible:bg-muted focus-visible:outline-none sm:min-h-8"
                                :style="{
                                    paddingLeft: `${0.5 + Math.min(part.depth, 8) * 0.75}rem`,
                                }"
                                @mouseenter="state.glance(index)"
                                @focus="state.glance(index)"
                                @blur="state.glance(null)"
                                @click="state.pickPart(index)"
                            >
                                <span
                                    :class="[
                                        'shrink-0',
                                        part.words
                                            ? 'text-muted-foreground'
                                            : '',
                                    ]"
                                    >{{ part.kind }}</span
                                >
                                <span
                                    v-if="part.words"
                                    class="min-w-0 truncate"
                                    >{{ part.words }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </section>
            </template>

            <template v-else>
                <header
                    class="sticky top-0 z-10 flex items-center gap-2 border-b bg-background px-4 py-2"
                >
                    <div class="min-w-0 flex-1">
                        <!-- The parts it sits in, outermost first, so the
                             owner sees where it is and can step out. -->
                        <nav
                            v-if="trail.length > 0"
                            aria-label="Where it is"
                            class="flex min-w-0 items-center text-xs text-muted-foreground"
                            data-test="part-trail"
                        >
                            <span v-if="trailCut" aria-hidden="true"
                                >…&nbsp;›&nbsp;</span
                            >
                            <template v-for="step in trail" :key="step.up">
                                <button
                                    type="button"
                                    class="min-h-11 max-w-28 shrink truncate select-none hover:text-foreground sm:min-h-0"
                                    :title="
                                        step.words
                                            ? `${step.kind}: ${step.words}`
                                            : step.kind
                                    "
                                    @mouseenter="state.glanceUp(step.up)"
                                    @focus="state.glanceUp(step.up)"
                                    @mouseleave="state.glanceUp(null)"
                                    @blur="state.glanceUp(null)"
                                    @click="state.pickUp(step.up)"
                                >
                                    {{ step.kind }}
                                </button>
                                <span aria-hidden="true" class="shrink-0"
                                    >&nbsp;›&nbsp;</span
                                >
                            </template>
                        </nav>
                        <p class="truncate text-sm font-medium">
                            {{
                                state.selected.text || element?.area?.name || ''
                            }}
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11 shrink-0 text-muted-foreground sm:size-7"
                        aria-label="Select the part around it"
                        aria-keyshortcuts="Shift+Enter"
                        title="Select the part around it (Shift+Enter)"
                        data-test="pick-parent"
                        @click="state.pickNear('parent')"
                    >
                        <ChevronUp class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11 shrink-0 text-muted-foreground sm:size-7"
                        aria-label="Select the first part inside it"
                        aria-keyshortcuts="Enter"
                        title="Select the first part inside it (Enter)"
                        data-test="pick-child"
                        @click="state.pickNear('child')"
                    >
                        <ChevronDown class="size-4" />
                    </Button>
                    <Button
                        v-if="element?.editable"
                        variant="ghost"
                        size="icon"
                        class="size-11 shrink-0 text-muted-foreground sm:size-7"
                        aria-label="Copy it"
                        aria-keyshortcuts="Control+D"
                        title="Copy it (Ctrl+D)"
                        data-test="part-copy"
                        :disabled="state.saving"
                        @click="state.reshape('duplicate')"
                    >
                        <Copy class="size-4" />
                    </Button>
                    <Button
                        v-if="element?.editable"
                        variant="ghost"
                        size="icon"
                        class="size-11 shrink-0 text-muted-foreground hover:text-destructive sm:size-7"
                        aria-label="Remove it"
                        title="Remove it (Undo puts it back)"
                        data-test="part-remove"
                        :disabled="state.saving"
                        @click="state.reshape('remove')"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11 shrink-0 sm:size-7"
                        aria-label="Stop changing this part"
                        @click="state.deselect()"
                    >
                        <X class="size-4" />
                    </Button>
                </header>

                <template v-if="element">
                    <div class="animate-in space-y-4 p-4 duration-base fade-in">
                        <p
                            v-if="element.area?.summary"
                            class="line-clamp-2 text-xs text-muted-foreground"
                            :title="element.area.summary"
                            data-test="element-area"
                        >
                            {{ element.area.summary }}
                        </p>

                        <p
                            v-if="element.origin"
                            class="text-xs text-muted-foreground"
                            data-test="element-origin"
                        >
                            {{
                                element.origin.how === 'added'
                                    ? 'Added'
                                    : 'Changed'
                            }}
                            {{ originWhen }} when you asked
                            <Link
                                :href="
                                    FeatureRequestController.show.url(
                                        element.origin.id,
                                    )
                                "
                                class="text-foreground underline decoration-muted-foreground/40 underline-offset-2 hover:decoration-foreground"
                                >“{{ element.origin.asked }}”</Link
                            ><template v-if="element.origin.decided"
                                >. You chose “{{
                                    element.origin.decided.answer
                                }}”</template
                            >.
                        </p>

                        <p
                            v-for="note in notes"
                            :key="note"
                            class="text-xs text-foreground"
                            data-test="element-note"
                        >
                            {{ note }}
                        </p>

                        <Segmented
                            v-if="
                                state.selected.instance && state.selected.source
                            "
                            label="Which ones change"
                            :value="state.onlyThisOne ? 'one' : 'all'"
                            :options="[
                                { value: 'one', label: 'Only this one' },
                                {
                                    value: 'all',
                                    label: element.shared
                                        ? `All ${element.shared.uses}`
                                        : 'All like it',
                                },
                            ]"
                            data-test="shared-choice"
                            @change="state.onlyThisOne = $event !== 'all'"
                        />

                        <p
                            v-if="!element.editable && element.reason"
                            class="rounded-md bg-muted p-3 text-sm"
                            data-test="not-editable"
                        >
                            {{ reasons[element.reason] }}
                        </p>

                        <div
                            v-else
                            class="space-y-5 text-sm"
                            data-test="properties"
                        >
                            <section
                                v-if="state.selected?.words != null"
                                class="space-y-2"
                            >
                                <h3 class="text-xs font-medium">Words</h3>
                                <textarea
                                    id="property-words"
                                    v-model="draft"
                                    rows="2"
                                    aria-label="Words"
                                    class="block [field-sizing:content] w-full resize-none rounded-md bg-muted px-3 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                                    data-test="words"
                                    @blur="keepWords"
                                    @keydown.enter.exact.prevent="keepWords"
                                    @keydown.escape="
                                        draft = state.selected?.words ?? ''
                                    "
                                />
                                <p class="text-xs text-muted-foreground">
                                    Or double-click the words in your app.
                                </p>
                            </section>

                            <section
                                v-if="element.link || state.selected?.href"
                                class="space-y-2"
                                data-test="link"
                            >
                                <div
                                    class="flex items-center justify-between gap-2"
                                >
                                    <h3 class="text-xs font-medium">Goes to</h3>
                                    <Button
                                        v-if="destination"
                                        variant="ghost"
                                        size="sm"
                                        class="-my-2 h-11 gap-1 px-2 text-xs sm:-my-1 sm:h-7"
                                        :title="`Go to ${state.addressOf(destination)} (or Ctrl+click the link in your app)`"
                                        data-test="link-follow"
                                        @click="state.follow(destination)"
                                    >
                                        Go there
                                        <ArrowUpRight class="size-3.5" />
                                    </Button>
                                </div>
                                <input
                                    v-if="element.link?.href != null"
                                    id="property-link"
                                    v-model="address"
                                    type="text"
                                    inputmode="url"
                                    autocomplete="off"
                                    spellcheck="false"
                                    aria-label="Goes to"
                                    placeholder="/page or https://…"
                                    class="block h-11 w-full rounded-md bg-muted px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50 sm:h-9"
                                    data-test="link-address"
                                    @blur="keepAddress"
                                    @keydown.enter.prevent="keepAddress"
                                    @keydown.escape="
                                        address = element.link?.href ?? ''
                                    "
                                />
                                <template v-else>
                                    <p
                                        v-if="state.selected?.href"
                                        class="truncate text-sm"
                                        data-test="link-shown"
                                    >
                                        {{
                                            state.addressOf(state.selected.href)
                                        }}
                                    </p>
                                    <p
                                        v-if="
                                            element.link ||
                                            state.selected?.tag === 'a'
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        Your app decides where this link goes.
                                        Ask me to change it.
                                    </p>
                                    <p
                                        v-else
                                        class="flex items-center justify-between gap-2 text-xs text-muted-foreground"
                                    >
                                        This sits in a link.
                                        <Button
                                            v-if="linkUp !== null"
                                            variant="secondary"
                                            size="sm"
                                            class="h-11 sm:h-7"
                                            data-test="pick-link"
                                            @click="state.pickUp(linkUp)"
                                            >Pick the link</Button
                                        >
                                        <template v-else
                                            >Pick the link to change where it
                                            goes.</template
                                        >
                                    </p>
                                </template>
                            </section>

                            <section class="space-y-2">
                                <h3 class="text-xs font-medium">Layout</h3>
                                <Segmented
                                    label="Arrange contents"
                                    caption="Arrange"
                                    :value="
                                        inline ? null : state.valueOf('layout')
                                    "
                                    :options="
                                        options('layout', [
                                            'block',
                                            'flex',
                                            'grid',
                                            'hidden',
                                        ])
                                    "
                                    @change="set('layout', $event)"
                                />
                                <Reveal :open="layout === 'flex'">
                                    <Segmented
                                        label="Direction"
                                        caption="Direction"
                                        :value="state.valueOf('direction')"
                                        :options="options('direction')"
                                        @change="set('direction', $event)"
                                    />
                                    <Segmented
                                        label="When there is no room"
                                        caption="If full"
                                        :value="state.valueOf('wrap')"
                                        :options="options('wrap')"
                                        @change="set('wrap', $event)"
                                    />
                                </Reveal>
                                <Reveal
                                    :open="
                                        layout === 'flex' || layout === 'grid'
                                    "
                                >
                                    <Segmented
                                        label="Line up"
                                        caption="Line up"
                                        :value="state.valueOf('align')"
                                        :options="options('align')"
                                        @change="set('align', $event)"
                                    />
                                    <Segmented
                                        label="Spread"
                                        caption="Spread"
                                        :value="state.valueOf('justify')"
                                        :options="options('justify')"
                                        @change="set('justify', $event)"
                                    />
                                    <div class="grid grid-cols-2 gap-2">
                                        <MeasureField
                                            :state="state"
                                            property="gap"
                                            mark="↔"
                                        />
                                        <MeasureField
                                            v-if="layout === 'grid'"
                                            :state="state"
                                            property="columns"
                                        />
                                    </div>
                                </Reveal>
                            </section>

                            <section class="space-y-2">
                                <h3 class="text-xs font-medium">Size</h3>
                                <div class="grid grid-cols-2 gap-2">
                                    <MeasureField
                                        :state="state"
                                        property="width"
                                        mark="W"
                                        :measured="state.selected.width"
                                    />
                                    <MeasureField
                                        :state="state"
                                        property="height"
                                        mark="H"
                                        :measured="state.selected.height"
                                    />
                                </div>
                                <Segmented
                                    label="Width"
                                    caption="Width"
                                    :value="state.valueOf('width')"
                                    :options="words('width')"
                                    @change="set('width', $event)"
                                />
                                <Segmented
                                    label="Height"
                                    caption="Height"
                                    :value="state.valueOf('height')"
                                    :options="words('height')"
                                    @change="set('height', $event)"
                                />
                                <StepSlider
                                    id="property-max_width"
                                    label="Widest"
                                    :value="state.valueOf('max_width')"
                                    :options="widest"
                                    :rest="widest.length - 1"
                                    :now="
                                        drawnStep(
                                            'max_width',
                                            state.selected?.drawn,
                                        )
                                    "
                                    @change="set('max_width', $event)"
                                />
                            </section>

                            <section class="space-y-2">
                                <h3 class="text-xs font-medium">Space</h3>
                                <SpacingBox :state="state" />
                            </section>

                            <section class="space-y-2">
                                <h3 class="text-xs font-medium">
                                    Turn and move
                                </h3>
                                <div class="grid grid-cols-3 gap-2">
                                    <MeasureField
                                        :state="state"
                                        property="rotate"
                                        mark="↻"
                                    />
                                    <MeasureField
                                        :state="state"
                                        property="translate_x"
                                        mark="X"
                                    />
                                    <MeasureField
                                        :state="state"
                                        property="translate_y"
                                        mark="Y"
                                    />
                                </div>
                                <div
                                    v-if="moves.length"
                                    class="grid grid-cols-2 gap-2"
                                >
                                    <Button
                                        v-for="move in moves"
                                        :key="move.step"
                                        variant="secondary"
                                        size="sm"
                                        class="h-11 sm:h-7"
                                        :disabled="!move.can || state.saving"
                                        :title="`Move it ${move.way}, past the part next to it (Alt + ${wayLooks[move.way].key})`"
                                        :data-test="
                                            move.step < 0
                                                ? 'move-earlier'
                                                : 'move-later'
                                        "
                                        @click="state.shift(move.step)"
                                    >
                                        <component
                                            :is="wayLooks[move.way].icon"
                                            class="size-4"
                                        />
                                        {{ wayLooks[move.way].name }}
                                    </Button>
                                </div>
                                <label class="flex items-center gap-3">
                                    <span
                                        class="w-14 text-xs text-muted-foreground"
                                        >Opacity</span
                                    >
                                    <input
                                        id="property-opacity-slider"
                                        type="range"
                                        min="0"
                                        max="100"
                                        :step="state.fine ? 1 : 5"
                                        :value="
                                            typeof state.valueOf('opacity') ===
                                            'number'
                                                ? state.valueOf('opacity')
                                                : 100
                                        "
                                        class="min-h-11 flex-1 accent-foreground sm:min-h-6"
                                        @pointerdown="state.hold(true)"
                                        @pointerup="state.hold(false)"
                                        @input="
                                            set(
                                                'opacity',
                                                Number(
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).value,
                                                ),
                                            )
                                        "
                                    />
                                    <span
                                        class="w-10 text-right text-xs tabular-nums"
                                        >{{
                                            state.valueOf('opacity') ?? 100
                                        }}%</span
                                    >
                                </label>
                            </section>

                            <section class="space-y-2">
                                <h3 class="text-xs font-medium">Text</h3>
                                <StepSlider
                                    id="property-text_size"
                                    label="Size"
                                    :value="state.valueOf('text_size')"
                                    :options="sizes"
                                    :rest="2"
                                    :now="
                                        drawnStep(
                                            'text_size',
                                            state.selected?.drawn,
                                        )
                                    "
                                    @change="set('text_size', $event)"
                                />
                                <Segmented
                                    label="Line up text"
                                    caption="Align"
                                    :value="state.valueOf('text_align')"
                                    :options="options('text_align')"
                                    @change="set('text_align', $event)"
                                />
                                <Segmented
                                    label="Text weight"
                                    caption="Weight"
                                    :value="state.valueOf('text_weight')"
                                    :options="
                                        options('text_weight').map(
                                            (option) => ({
                                                ...option,
                                                style: {
                                                    fontWeight:
                                                        weights[option.value],
                                                },
                                            }),
                                        )
                                    "
                                    @change="set('text_weight', $event)"
                                />
                                <Swatches
                                    label="Colour"
                                    name="Text colour"
                                    kind="color"
                                    :colors="state.theme"
                                    :value="state.valueOf('text_color')"
                                    :own="state.selected?.colors?.text_color"
                                    :options="options('text_color')"
                                    @change="set('text_color', $event)"
                                />
                            </section>

                            <section class="space-y-2">
                                <h3 class="text-xs font-medium">
                                    Fill and edges
                                </h3>
                                <Swatches
                                    label="Fill"
                                    kind="color"
                                    :colors="state.theme"
                                    :value="state.valueOf('background')"
                                    :own="state.selected?.colors?.background"
                                    :options="options('background')"
                                    @change="set('background', $event)"
                                />
                                <Swatches
                                    label="Corners"
                                    kind="radius"
                                    :value="state.valueOf('radius')"
                                    :options="options('radius')"
                                    @change="set('radius', $event)"
                                />
                                <StepSlider
                                    id="property-shadow"
                                    label="Shadow"
                                    :value="state.valueOf('shadow')"
                                    :options="options('shadow')"
                                    @change="set('shadow', $event)"
                                />
                                <div class="flex items-center gap-3">
                                    <span
                                        class="w-14 shrink-0 text-xs text-muted-foreground"
                                        >Border</span
                                    >
                                    <MeasureField
                                        class="w-24"
                                        :state="state"
                                        property="border"
                                    />
                                </div>
                                <Reveal
                                    :open="
                                        Number(state.valueOf('border') ?? 0) >
                                            0 ||
                                        state.valueOf('border_color') != null
                                    "
                                >
                                    <Swatches
                                        label="Colour"
                                        name="Border colour"
                                        kind="color"
                                        :colors="state.theme"
                                        :value="state.valueOf('border_color')"
                                        :own="
                                            state.selected?.colors?.border_color
                                        "
                                        :options="options('border_color')"
                                        @change="set('border_color', $event)"
                                    />
                                </Reveal>
                            </section>
                        </div>

                        <!-- The form arrives rather than cutting in; the button
                             goes at once. -->
                        <Transition
                            enter-active-class="transition duration-base ease-settle"
                            enter-from-class="opacity-0 translate-y-1"
                        >
                            <button
                                v-if="!asking"
                                type="button"
                                class="flex min-h-11 w-full items-center gap-2 rounded-md border border-dashed px-3 text-sm text-muted-foreground select-none hover:text-foreground sm:min-h-9"
                                data-test="ask-instead-open"
                                @click="asking = true"
                            >
                                <MessageSquare class="size-4" /> Ask me to
                                change it
                            </button>
                            <Form
                                v-else
                                v-bind="
                                    FeatureRequestController.store.form(
                                        projectId,
                                    )
                                "
                                v-slot="{ errors, processing }"
                                class="space-y-2"
                            >
                                <input
                                    type="hidden"
                                    name="selection[file]"
                                    :value="element.file"
                                />
                                <input
                                    type="hidden"
                                    name="selection[line]"
                                    :value="element.line"
                                />
                                <input
                                    type="hidden"
                                    name="selection[column]"
                                    :value="element.target.split(':').pop()"
                                />
                                <input
                                    type="hidden"
                                    name="selection[tag]"
                                    :value="element.tag ?? state.selected.tag"
                                />
                                <input
                                    type="hidden"
                                    name="selection[text]"
                                    :value="state.selected.text"
                                />
                                <input
                                    v-if="element.area"
                                    type="hidden"
                                    name="selection[area]"
                                    :value="element.area.name"
                                />
                                <textarea
                                    name="prompt"
                                    rows="2"
                                    required
                                    aria-label="Your change"
                                    class="w-full rounded-md border bg-transparent px-3 py-2 text-base placeholder:text-muted-foreground md:text-sm"
                                    placeholder="Show the price next to each item"
                                />
                                <InputError :message="errors.prompt" />
                                <p
                                    v-if="reach"
                                    class="text-xs text-muted-foreground"
                                    data-test="element-reach"
                                >
                                    {{ reach }}
                                </p>
                                <Button
                                    :disabled="processing"
                                    class="h-11 w-full select-none sm:h-8"
                                >
                                    Ask for this change
                                </Button>
                            </Form>
                        </Transition>

                        <section
                            v-if="showCode"
                            class="space-y-1 border-t pt-3 text-xs"
                            data-test="element-code"
                        >
                            <p
                                class="truncate font-mono text-muted-foreground"
                                :title="`${element.file}:${element.line}`"
                            >
                                {{ element.file }}:{{ element.line }}
                            </p>
                            <p
                                v-if="element.classes"
                                class="font-mono break-words select-all"
                            >
                                {{ element.classes }}
                            </p>
                        </section>
                    </div>
                </template>

                <!-- While the part's details load: its shape, not a word,
                     and only when the answer is slow, so it never flashes. -->
                <div
                    v-else
                    class="animate-in space-y-6 p-4 delay-300 duration-base fill-mode-both fade-in"
                    aria-busy="true"
                    aria-label="Loading this part"
                    data-test="part-loading"
                >
                    <div
                        v-for="(rows, section) in [3, 2, 2]"
                        :key="section"
                        class="space-y-3"
                    >
                        <div class="h-3 w-16 animate-pulse rounded bg-muted" />
                        <div
                            v-for="row in rows"
                            :key="row"
                            class="flex items-center gap-3"
                        >
                            <div
                                class="h-3 w-14 shrink-0 animate-pulse rounded bg-muted/70"
                            />
                            <div
                                class="h-8 flex-1 animate-pulse rounded-md bg-muted/70"
                            />
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <footer
            v-if="preview?.status === 'ready'"
            class="flex items-center gap-1 border-t bg-background px-2 py-1"
            data-test="design-status"
        >
            <Button
                variant="ghost"
                size="icon"
                class="size-11 shrink-0 sm:size-8"
                :disabled="!state.canUndo"
                aria-label="Undo"
                title="Undo (Ctrl+Z)"
                data-test="undo"
                @click="state.press('undo')"
            >
                <Undo2 class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-11 shrink-0 sm:size-8"
                :disabled="!state.canRedo"
                aria-label="Redo"
                title="Redo (Ctrl+Shift+Z)"
                data-test="redo"
                @click="state.press('redo')"
            >
                <Redo2 class="size-4" />
            </Button>
            <button
                type="button"
                :aria-pressed="state.fine"
                :title="
                    state.fine
                        ? 'Any value. Turn off to snap to the scale.'
                        : 'Values snap to the scale. Turn on for exact values, or hold Alt while dragging.'
                "
                :class="[
                    'flex h-11 shrink-0 items-center gap-1.5 rounded-md px-2 text-xs select-none sm:h-8',
                    state.fine
                        ? 'bg-foreground text-background'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                data-test="fine-tune"
                @click="state.fine = !state.fine"
            >
                <Crosshair class="size-3.5" />
                Fine tune
            </button>
            <p
                v-if="state.saveError"
                class="min-w-0 flex-1 px-2 text-xs text-destructive"
                role="alert"
                data-test="save-error"
            >
                {{ state.saveError }}
            </p>
            <p
                v-else-if="state.saving"
                class="ml-auto flex items-center gap-1.5 px-2 text-xs text-muted-foreground"
                data-test="saving"
            >
                <LoaderCircle class="size-3.5 animate-spin" />
                Saving
            </p>
            <p
                v-else-if="edits.length > 0"
                class="ml-auto flex items-center gap-1.5 px-2 text-xs text-muted-foreground"
                data-test="saved"
            >
                <Check class="size-3.5" />
                Saved
            </p>
        </footer>
    </div>
</template>
