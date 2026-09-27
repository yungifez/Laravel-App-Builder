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
    ArrowRight,
    ArrowRightToLine,
    Baseline,
    Columns3,
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
    Undo2,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import PageConsistencyController from '@/actions/App/Http/Controllers/PageConsistencyController';
import MeasureField from '@/components/design/MeasureField.vue';
import Segmented from '@/components/design/Segmented.vue';
import SpacingBox from '@/components/design/SpacingBox.vue';
import StepSlider from '@/components/design/StepSlider.vue';
import Swatches from '@/components/design/Swatches.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { AppPreviewState } from '@/composables/useAppPreview';
import { when } from '@/lib/when';
import {
    definition,
    describeValue,
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

function set(property: VisualProperty, value: VisualValue | null): void {
    props.state.change(property, value);
}

const reasons: Record<NonNullable<InspectedElement['reason']>, string> = {
    updating: 'Your last change is still going in. Try again in a moment.',
    behind: 'Your app is showing an older version. Start it again to change this part.',
    not_found: "I can't find this part. Ask me to change it instead.",
    dynamic: 'This part changes while the app runs. Ask me instead.',
};

// What a saved edit changed, in a few words.
function describeEdit(edit: VisualEditSummary): string {
    if (edit.kind === 'move') {
        return 'Moved';
    }

    if (edit.kind === 'text') {
        return 'Words';
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

    if (edit.kind === 'move' || edit.properties.length !== 1) {
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
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                v-if="!preview || preview.status !== 'ready'"
                class="flex items-center justify-center gap-2 p-4 text-sm text-muted-foreground lg:flex-col lg:py-16"
            >
                <MousePointerClick class="size-5 lg:size-8" />
                Open your app first
            </div>

            <template v-else-if="state.selected === null">
                <div
                    class="flex items-center justify-center gap-2 p-4 text-sm text-muted-foreground lg:flex-col lg:py-16"
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
            </template>

            <template v-else-if="element">
                <header
                    class="sticky top-0 z-10 flex items-center gap-2 border-b bg-background px-4 py-2"
                >
                    <span class="min-w-0 flex-1 truncate text-sm font-medium">{{
                        state.selected.text || element.area?.name || ''
                    }}</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-11 shrink-0 text-muted-foreground sm:size-7"
                        aria-label="Select the part around it"
                        title="Select the part around it"
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
                        title="Select the first part inside it"
                        data-test="pick-child"
                        @click="state.pickNear('child')"
                    >
                        <ChevronDown class="size-4" />
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

                <div class="space-y-4 p-4">
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
                            element.origin.how === 'added' ? 'Added' : 'Changed'
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

                    <Segmented
                        v-if="state.selected.instance && state.selected.source"
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

                        <section class="space-y-2">
                            <h3 class="text-xs font-medium">Layout</h3>
                            <Segmented
                                label="Arrange contents"
                                caption="Arrange"
                                :value="inline ? null : state.valueOf('layout')"
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
                            <div v-if="layout === 'flex'" class="space-y-2">
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
                            </div>
                            <template
                                v-if="layout === 'flex' || layout === 'grid'"
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
                            </template>
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
                            <label
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="text-xs text-muted-foreground"
                                    >Widest</span
                                >
                                <select
                                    id="property-max_width"
                                    :value="state.valueOf('max_width') ?? ''"
                                    class="h-11 w-40 rounded-md bg-muted px-2 text-sm sm:h-7"
                                    @change="
                                        set(
                                            'max_width',
                                            ($event.target as HTMLSelectElement)
                                                .value || null,
                                        )
                                    "
                                >
                                    <option value="">Not set</option>
                                    <option
                                        v-for="option in options('max_width')"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>
                        </section>

                        <section class="space-y-2">
                            <h3 class="text-xs font-medium">Space</h3>
                            <SpacingBox :state="state" />
                        </section>

                        <section class="space-y-2">
                            <h3 class="text-xs font-medium">Turn and move</h3>
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
                                v-if="
                                    state.neighbours.earlier ||
                                    state.neighbours.later
                                "
                                class="grid grid-cols-2 gap-2"
                            >
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    class="h-11 sm:h-7"
                                    :disabled="
                                        !state.neighbours.earlier ||
                                        state.saving
                                    "
                                    title="Put it before the part next to it (Alt + ←)"
                                    data-test="move-earlier"
                                    @click="state.shift(-1)"
                                    >Earlier</Button
                                >
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    class="h-11 sm:h-7"
                                    :disabled="
                                        !state.neighbours.later || state.saving
                                    "
                                    title="Put it after the part next to it (Alt + →)"
                                    data-test="move-later"
                                    @click="state.shift(1)"
                                    >Later</Button
                                >
                            </div>
                            <label class="flex items-center gap-3">
                                <span class="w-14 text-xs text-muted-foreground"
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
                                @change="set('text_size', $event)"
                            />
                            <Segmented
                                label="Line up text"
                                caption="Align"
                                :value="state.valueOf('text_align')"
                                :options="options('text_align')"
                                @change="set('text_align', $event)"
                            />
                            <div class="flex items-center gap-3">
                                <span
                                    class="w-14 shrink-0 text-xs text-muted-foreground"
                                    aria-hidden="true"
                                    >Weight</span
                                >
                                <div
                                    class="flex min-w-0 flex-1 rounded-md bg-muted p-0.5"
                                    role="group"
                                    aria-label="Text weight"
                                >
                                    <button
                                        v-for="option in options('text_weight')"
                                        :key="option.value"
                                        type="button"
                                        :aria-pressed="
                                            state.valueOf('text_weight') ===
                                            option.value
                                        "
                                        :aria-label="option.label"
                                        :title="option.label"
                                        :style="{
                                            fontWeight: weights[option.value],
                                        }"
                                        :class="[
                                            'min-h-11 min-w-0 flex-1 truncate rounded px-1 text-xs select-none sm:min-h-7',
                                            state.valueOf('text_weight') ===
                                            option.value
                                                ? 'bg-background shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground',
                                        ]"
                                        @click="
                                            set(
                                                'text_weight',
                                                state.valueOf('text_weight') ===
                                                    option.value
                                                    ? null
                                                    : option.value,
                                            )
                                        "
                                    >
                                        {{ option.short ?? option.label }}
                                    </button>
                                </div>
                            </div>
                            <Swatches
                                label="Colour"
                                kind="color"
                                :colors="state.theme"
                                :value="state.valueOf('text_color')"
                                :options="options('text_color')"
                                @change="set('text_color', $event)"
                            />
                        </section>

                        <section class="space-y-2">
                            <h3 class="text-xs font-medium">Fill and edges</h3>
                            <Swatches
                                label="Fill"
                                kind="color"
                                :colors="state.theme"
                                :value="state.valueOf('background')"
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
                        </section>
                    </div>

                    <button
                        v-if="!asking"
                        type="button"
                        class="flex min-h-11 w-full items-center gap-2 rounded-md border border-dashed px-3 text-sm text-muted-foreground select-none hover:text-foreground sm:min-h-9"
                        data-test="ask-instead-open"
                        @click="asking = true"
                    >
                        <MessageSquare class="size-4" /> Ask me to change it
                    </button>
                    <Form
                        v-else
                        v-bind="FeatureRequestController.store.form(projectId)"
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
                        <Button
                            :disabled="processing"
                            class="h-11 w-full select-none sm:h-8"
                        >
                            Ask for this change
                        </Button>
                    </Form>

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

            <p v-else class="p-4 text-sm text-muted-foreground">Looking…</p>
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
