<script setup lang="ts">
import { computed } from 'vue';
import type { VisualValue } from '@/types';

// One step on a scale that only goes up, such as text size or shadow,
// named in words beside the slider.
const props = defineProps<{
    label: string;
    id: string;
    value: VisualValue | null;
    options: { value: VisualValue; label: string }[];
    /** Where the slider rests while nothing is set. */
    rest?: number;
}>();

const emit = defineEmits<{ change: [value: VisualValue] }>();

const index = computed(() =>
    props.options.findIndex((option) => option.value === props.value),
);
</script>

<template>
    <label class="flex items-center gap-3">
        <span class="w-14 shrink-0 text-xs text-muted-foreground">{{
            label
        }}</span>
        <input
            :id="id"
            type="range"
            min="0"
            :max="options.length - 1"
            :value="index < 0 ? (rest ?? 0) : index"
            class="min-h-11 min-w-0 flex-1 accent-foreground sm:min-h-6"
            @input="
                emit(
                    'change',
                    options[Number(($event.target as HTMLInputElement).value)]
                        .value,
                )
            "
        />
        <span
            :class="[
                'w-20 shrink-0 truncate text-right text-xs',
                index < 0 && 'text-muted-foreground',
            ]"
            >{{ index < 0 ? 'Not set' : options[index].label }}</span
        >
    </label>
</template>
