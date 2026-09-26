<script setup lang="ts">
import type { VisualValue } from '@/types';

defineProps<{
    id?: string;
    label: string;
    value: VisualValue | null;
    placeholder?: string;
    allowNegative?: boolean;
    max?: number;
    unit?: string;
}>();

const emit = defineEmits<{ change: [value: number | null] }>();

function change(event: Event): void {
    const raw = (event.target as HTMLInputElement).value;

    emit('change', raw === '' ? null : Number(raw));
}
</script>

<template>
    <label
        class="relative flex h-11 min-w-0 items-center gap-1 rounded-md bg-muted px-2 text-sm focus-within:ring-2 focus-within:ring-ring/50 sm:h-7"
        :title="label"
    >
        <span class="sr-only">{{ label }}</span>
        <input
            :id="id"
            type="number"
            :min="allowNegative ? undefined : 0"
            :max="max"
            :value="typeof value === 'number' ? value : ''"
            :placeholder="
                value === 'auto'
                    ? 'auto'
                    : value === 'mixed'
                      ? 'mixed'
                      : (placeholder ?? '–')
            "
            class="w-full min-w-0 bg-transparent tabular-nums outline-none placeholder:text-muted-foreground"
            @change="change"
        />
        <span v-if="unit" class="text-xs text-muted-foreground">{{
            unit
        }}</span>
    </label>
</template>
