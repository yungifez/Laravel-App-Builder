<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type { Component, StyleValue } from 'vue';
import type { VisualValue } from '@/types';

const props = defineProps<{
    label: string;
    /** A word shown before the choices, for a row of the panel. */
    caption?: string;
    value: VisualValue | null;
    options: {
        value: VisualValue;
        label: string;
        /** A shorter word for the button when the label does not fit. */
        short?: string;
        icon?: Component;
        style?: StyleValue;
    }[];
}>();

const emit = defineEmits<{ change: [value: VisualValue | null] }>();

const chosen = computed(() =>
    props.options.findIndex((option) => option.value === props.value),
);

// The pill slides from one choice to the next. When nothing was chosen
// before, it has no place to slide from, so it fades in where it lands.
// Choices are as wide as their words, so the pill takes the chosen one's
// place and width from the page.
const at = ref(Math.max(chosen.value, 0));
const slides = ref(true);
const group = ref<HTMLElement | null>(null);
const place = ref({ left: 0, width: 0 });

function measure(): void {
    const button = group.value?.querySelectorAll('button')[at.value];

    if (button) {
        place.value = { left: button.offsetLeft, width: button.offsetWidth };
    }
}

let resized: ResizeObserver | null = null;

onMounted(() => {
    measure();
    resized = new ResizeObserver(measure);

    if (group.value) {
        resized.observe(group.value);
    }
});

onBeforeUnmount(() => resized?.disconnect());

watch(chosen, (now, before) => {
    if (now < 0) {
        return;
    }

    slides.value = before >= 0;
    at.value = now;
    void nextTick(measure);

    if (!slides.value) {
        requestAnimationFrame(() =>
            requestAnimationFrame(() => (slides.value = true)),
        );
    }
});
</script>

<template>
    <!-- One choice out of a few, shown side by side. Choosing the chosen one
         again clears it. -->
    <div :class="caption && 'flex items-center gap-3'">
        <span
            v-if="caption"
            class="w-14 shrink-0 text-xs text-muted-foreground"
            aria-hidden="true"
            >{{ caption }}</span
        >
        <div
            ref="group"
            class="relative flex min-w-0 flex-1 rounded-md bg-muted p-0.5"
            role="group"
            :aria-label="label"
        >
            <span
                aria-hidden="true"
                :class="[
                    'absolute inset-y-0.5 left-0 rounded bg-background shadow-sm duration-base ease-snap',
                    slides
                        ? 'transition-[translate,width,opacity]'
                        : 'transition-opacity',
                    chosen < 0 && 'opacity-0',
                ]"
                :style="{
                    width: `${place.width}px`,
                    translate: `${place.left}px 0`,
                }"
                data-test="segmented-pill"
            />
            <button
                v-for="option in options"
                :key="option.value"
                type="button"
                :aria-pressed="value === option.value"
                :aria-label="option.label"
                :title="option.label"
                :style="option.style"
                :class="[
                    'relative flex min-h-11 min-w-0 flex-auto items-center justify-center rounded px-1.5 text-xs transition-colors duration-quick select-none sm:min-h-7',
                    value === option.value
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="
                    emit('change', value === option.value ? null : option.value)
                "
            >
                <component
                    :is="option.icon"
                    v-if="option.icon"
                    class="size-4"
                />
                <span v-else class="truncate">{{
                    option.short ?? option.label
                }}</span>
            </button>
        </div>
    </div>
</template>
