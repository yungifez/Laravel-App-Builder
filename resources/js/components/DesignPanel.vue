<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
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
    ArrowDown,
    ArrowRight,
    ArrowRightToLine,
    Baseline,
    Columns3,
    EyeOff,
    LayoutGrid,
    MessageSquare,
    MousePointerClick,
    Rows3,
    StretchVertical,
    TextWrap,
    Undo2,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import FeatureRequestController from '@/actions/App/Http/Controllers/FeatureRequestController';
import VisualEditReversionController from '@/actions/App/Http/Controllers/VisualEditReversionController';
import PixelField from '@/components/design/PixelField.vue';
import Segmented from '@/components/design/Segmented.vue';
import SpacingBox from '@/components/design/SpacingBox.vue';
import Swatches from '@/components/design/Swatches.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { AppPreviewState } from '@/composables/useAppPreview';
import { devices, properties, weights } from '@/lib/visualProperties';
import type {
    Device,
    EditorPreview,
    InspectedElement,
    VisualEditSummary,
    VisualProperty,
    VisualValue,
} from '@/types';

const props = defineProps<{
    projectId: number;
    preview: EditorPreview | null;
    element?: InspectedElement | null;
    edits: VisualEditSummary[];
    state: AppPreviewState;
}>();

const page = usePage();
const asking = ref(false);

const deviceLabel = (key: Device) =>
    devices.find((option) => option.key === key)?.label ?? key;

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
};

function options(
    property: VisualProperty,
    only?: VisualValue[],
): { value: VisualValue; label: string; icon?: Component }[] {
    const definition = properties.find((item) => item.key === property);

    if (definition?.input.kind !== 'choice') {
        return [];
    }

    return definition.input.options
        .filter((option) => only === undefined || only.includes(option.value))
        .map((option) => ({
            ...option,
            icon: icons[`${property}:${option.value}`],
        }));
}

const widthChoices = [
    { value: 'auto', label: 'Auto' },
    { value: 'fit', label: 'Fit' },
    { value: 'full', label: 'Fill' },
    { value: '50%', label: '½' },
    { value: '33.33%', label: '⅓' },
    { value: '25%', label: '¼' },
];

const layout = computed(() =>
    String(props.state.valueOf('layout') ?? '').replace('inline-', ''),
);
const inline = computed(() =>
    String(props.state.valueOf('layout') ?? '').startsWith('inline'),
);

const sizes = options('text_size');
const sizeIndex = computed(() =>
    sizes.findIndex(
        (option) => option.value === props.state.valueOf('text_size'),
    ),
);

function set(property: VisualProperty, value: VisualValue | null): void {
    props.state.changes[property] = value;
}

const reasons: Record<NonNullable<InspectedElement['reason']>, string> = {
    updating: 'Your last change is still going in. Try again in a moment.',
    not_found: "I can't find this part. Ask me to change it instead.",
    dynamic: 'This part changes while the app runs. Ask me instead.',
};

// What a saved edit changed, in a few words.
function describeEdit(edit: VisualEditSummary): string {
    return edit.properties
        .map(
            (key) =>
                properties.find((property) => property.key === key)?.label ??
                key,
        )
        .join(', ');
}
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

                <section
                    v-if="edits.length > 0"
                    class="hidden px-4 pb-4 lg:block"
                    data-test="recent-edits"
                >
                    <h3 class="pb-1 text-xs font-medium text-muted-foreground">
                        Recent
                    </h3>
                    <ul class="text-sm">
                        <li
                            v-for="edit in edits"
                            :key="edit.id"
                            class="flex min-h-11 items-center justify-between gap-2 sm:min-h-9"
                        >
                            <span
                                :class="[
                                    'min-w-0 truncate',
                                    edit.reverted_at &&
                                        'text-muted-foreground line-through',
                                ]"
                                >{{ describeEdit(edit) }}</span
                            >
                            <Form
                                v-if="!edit.reverted_at"
                                v-bind="
                                    VisualEditReversionController.store.form(
                                        edit.id,
                                    )
                                "
                                :options="{
                                    preserveScroll: true,
                                    preserveState: true,
                                }"
                                v-slot="{ processing }"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-11 shrink-0 text-muted-foreground sm:size-7"
                                    :disabled="processing"
                                    :aria-label="`Undo ${describeEdit(edit)}`"
                                    title="Undo"
                                    :data-test="`undo-edit-${edit.id}`"
                                >
                                    <Undo2 class="size-3.5" />
                                </Button>
                            </Form>
                        </li>
                    </ul>
                    <InputError :message="page.props.errors?.edit" />
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
                        <section class="space-y-2">
                            <h3 class="text-xs font-medium">Layout</h3>
                            <Segmented
                                label="Arrange contents"
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
                            <div
                                v-if="layout === 'flex'"
                                class="grid grid-cols-2 gap-2"
                            >
                                <Segmented
                                    label="Direction"
                                    :value="state.valueOf('direction')"
                                    :options="options('direction')"
                                    @change="set('direction', $event)"
                                />
                                <Segmented
                                    label="When there is no room"
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
                                    :value="state.valueOf('align')"
                                    :options="options('align')"
                                    @change="set('align', $event)"
                                />
                                <Segmented
                                    label="Spread"
                                    :value="state.valueOf('justify')"
                                    :options="options('justify')"
                                    @change="set('justify', $event)"
                                />
                                <div class="grid grid-cols-2 gap-2">
                                    <PixelField
                                        label="Space between items"
                                        :value="state.valueOf('gap')"
                                        unit="px"
                                        @change="set('gap', $event)"
                                    />
                                    <PixelField
                                        v-if="layout === 'grid'"
                                        label="Columns"
                                        :value="state.valueOf('columns')"
                                        :max="12"
                                        unit="cols"
                                        @change="set('columns', $event)"
                                    />
                                </div>
                            </template>
                        </section>

                        <section class="space-y-2">
                            <h3 class="text-xs font-medium">Size</h3>
                            <Segmented
                                label="Width"
                                :value="state.valueOf('width')"
                                :options="widthChoices"
                                @change="set('width', $event)"
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
                            <SpacingBox
                                :value-of="state.valueOf"
                                @change="set"
                            />
                        </section>

                        <section class="space-y-2">
                            <h3 class="text-xs font-medium">Text</h3>
                            <label class="flex items-center gap-3">
                                <span class="w-14 text-xs text-muted-foreground"
                                    >Size</span
                                >
                                <input
                                    id="property-text_size"
                                    type="range"
                                    min="0"
                                    :max="sizes.length - 1"
                                    :value="sizeIndex < 0 ? 2 : sizeIndex"
                                    class="min-h-11 flex-1 accent-foreground sm:min-h-6"
                                    @input="
                                        set(
                                            'text_size',
                                            sizes[
                                                Number(
                                                    (
                                                        $event.target as HTMLInputElement
                                                    ).value,
                                                )
                                            ].value,
                                        )
                                    "
                                />
                                <span
                                    class="w-20 truncate text-right text-xs"
                                    >{{
                                        sizeIndex < 0
                                            ? 'Not set'
                                            : sizes[sizeIndex].label
                                    }}</span
                                >
                            </label>
                            <div
                                class="flex rounded-md bg-muted p-0.5"
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
                                        'min-h-11 flex-1 rounded text-sm select-none sm:min-h-7',
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
                                    Aa
                                </button>
                            </div>
                            <Swatches
                                label="Colour"
                                kind="color"
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
                            <Swatches
                                label="Shadow"
                                kind="shadow"
                                :value="state.valueOf('shadow')"
                                :options="options('shadow')"
                                @change="set('shadow', $event)"
                            />
                            <div class="flex items-center gap-2">
                                <span
                                    class="w-14 shrink-0 text-xs text-muted-foreground"
                                    >Border</span
                                >
                                <PixelField
                                    class="w-24"
                                    label="Border"
                                    :value="state.valueOf('border')"
                                    unit="px"
                                    @change="set('border', $event)"
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
                </div>
            </template>

            <p v-else class="p-4 text-sm text-muted-foreground">Looking…</p>
        </div>

        <footer
            v-if="state.hasChanges || state.saveError"
            class="space-y-2 border-t bg-background p-3"
        >
            <p
                v-if="state.saveError"
                class="text-sm text-destructive"
                data-test="save-error"
            >
                {{ state.saveError }}
            </p>
            <div class="flex items-center gap-2">
                <span class="mr-auto text-xs text-muted-foreground"
                    >On {{ deviceLabel(state.device) }}</span
                >
                <Button
                    variant="ghost"
                    :disabled="!state.hasChanges || state.saving"
                    class="h-11 select-none sm:h-8"
                    @click="state.clearChanges()"
                >
                    Discard
                </Button>
                <Button
                    :disabled="
                        !state.hasChanges ||
                        state.saving ||
                        preview?.updating === true
                    "
                    class="h-11 select-none sm:h-8"
                    data-test="save-look-button"
                    @click="state.save()"
                >
                    Save
                </Button>
            </div>
        </footer>
    </div>
</template>
