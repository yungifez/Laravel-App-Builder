<script setup lang="ts">
import type { Component } from 'vue';
import type { VisualValue } from '@/types';

defineProps<{
    label: string;
    value: VisualValue | null;
    options: { value: VisualValue; label: string; icon?: Component }[];
}>();

const emit = defineEmits<{ change: [value: VisualValue | null] }>();
</script>

<template>
    <!-- One choice out of a few, shown side by side. Choosing the chosen one
         again clears it. -->
    <div
        class="flex rounded-md bg-muted p-0.5"
        role="group"
        :aria-label="label"
    >
        <button
            v-for="option in options"
            :key="option.value"
            type="button"
            :aria-pressed="value === option.value"
            :aria-label="option.label"
            :title="option.label"
            :class="[
                'flex min-h-11 flex-1 items-center justify-center rounded px-1.5 text-xs select-none sm:min-h-7',
                value === option.value
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:text-foreground',
            ]"
            @click="
                emit('change', value === option.value ? null : option.value)
            "
        >
            <component :is="option.icon" v-if="option.icon" class="size-4" />
            <template v-else>{{ option.label }}</template>
        </button>
    </div>
</template>
