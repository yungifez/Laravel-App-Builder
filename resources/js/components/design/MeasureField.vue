<script setup lang="ts">
import { computed, nextTick } from 'vue';
import type { AppPreviewState } from '@/composables/useAppPreview';
import { definition } from '@/lib/visualProperties';
import type { VisualProperty, VisualValue } from '@/types';

// A number for one property, read from its definition: its unit, its
// scale and the words it can be instead. Arrow keys step along the scale
// (Shift for bigger steps); dragging the unit sideways does the same.
const props = defineProps<{
    state: AppPreviewState;
    property: VisualProperty;
    /** A letter or sign shown in the field, such as "W". */
    mark?: string;
    label?: string;
    /** What the part measures now, shown faintly when nothing is set. */
    measured?: number;
}>();

const input = computed(() => definition(props.property).input);
const name = computed(() => props.label ?? definition(props.property).label);
const unit = computed(() =>
    input.value.kind === 'measure' ? input.value.unit : '',
);
const words = computed(() =>
    input.value.kind === 'measure' ? (input.value.keywords ?? []) : [],
);
const value = computed(() => props.state.valueOf(props.property));

// Sides set apart have no one unit, and the word needs the room.
const mixed = computed(() => value.value === 'mixed');
// The unit belongs to a number: "Fill px" or "Mixed px" reads wrong.
const numeric = computed(
    () => value.value === null || typeof value.value === 'number',
);

const shown = computed(() =>
    typeof value.value === 'number' ? String(value.value) : '',
);

const placeholder = computed(() => {
    const current = value.value;

    if (current === null) {
        return props.measured === undefined ? '–' : String(props.measured);
    }

    if (current === 'mixed') {
        return 'Mixed';
    }

    return (
        words.value.find((word) => word.value === current)?.short ??
        String(current)
    );
});

// Read what the owner typed: a number (units allowed), one of the words,
// or a percentage where the property takes words such as "full".
function read(raw: string): VisualValue | null | undefined {
    const text = raw.trim().toLowerCase();

    if (text === '') {
        return null;
    }

    const word = words.value.find((option) =>
        [option.value, option.label, option.short].some(
            (name) => String(name ?? '').toLowerCase() === text,
        ),
    );

    if (word !== undefined) {
        return word.value;
    }

    if (words.value.length > 0 && /^\d+(\.\d+)?%$/.test(text)) {
        return text;
    }

    const number = Number.parseFloat(text);

    return Number.isFinite(number) ? number : undefined;
}

function commit(event: Event): void {
    const field = event.target as HTMLInputElement;
    const typed = read(field.value);

    if (typed !== undefined) {
        props.state.change(props.property, typed);
    }

    // Snapping can land on the value already shown, which would leave the
    // typed text in the field.
    nextTick(() => (field.value = shown.value));
}

function key(event: KeyboardEvent): void {
    if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
        return;
    }

    event.preventDefault();
    props.state.nudge(
        props.property,
        event.key === 'ArrowUp' ? 1 : -1,
        event.shiftKey,
    );
}

// Drag sideways on the unit: a step every few pixels.
const STEP_PX = 6;
let scrub: number | null = null;

function scrubStart(event: PointerEvent): void {
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    scrub = event.clientX;
    props.state.hold(true);
}

function scrubMove(event: PointerEvent): void {
    if (scrub === null) {
        return;
    }

    const steps = Math.trunc((event.clientX - scrub) / STEP_PX);

    for (let count = 0; count < Math.abs(steps); count++) {
        props.state.nudge(props.property, steps > 0 ? 1 : -1, event.shiftKey);
    }

    scrub += steps * STEP_PX;
}

function scrubEnd(): void {
    if (scrub !== null) {
        scrub = null;
        props.state.hold(false);
    }
}
</script>

<template>
    <label
        class="relative flex h-11 min-w-0 items-center gap-1 rounded-md bg-muted px-2 text-sm focus-within:ring-2 focus-within:ring-ring/50 sm:h-7"
        :title="mixed ? `${name}: different on each side` : name"
    >
        <span class="sr-only">{{ name }}</span>
        <span
            v-if="mark"
            class="w-3 shrink-0 cursor-ew-resize touch-none text-xs text-muted-foreground select-none"
            aria-hidden="true"
            @pointerdown="scrubStart"
            @pointermove="scrubMove"
            @pointerup="scrubEnd"
            @pointercancel="scrubEnd"
            >{{ mark }}</span
        >
        <input
            :id="`property-${property}`"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :value="shown"
            :placeholder="placeholder"
            class="w-full min-w-0 bg-transparent tabular-nums outline-none placeholder:text-muted-foreground"
            @change="commit"
            @keydown="key"
        />
        <span
            v-if="unit && numeric"
            class="shrink-0 cursor-ew-resize touch-none text-xs text-muted-foreground select-none"
            aria-hidden="true"
            @pointerdown="scrubStart"
            @pointermove="scrubMove"
            @pointerup="scrubEnd"
            @pointercancel="scrubEnd"
            >{{ unit }}</span
        >
    </label>
</template>
